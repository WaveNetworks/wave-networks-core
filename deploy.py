#!/usr/bin/env python3
"""
SFTP deployment script for admin (wave-networks-core)
Uploads changed files (based on git diff) to the remote server via SFTP.
Does NOT create commits or modify any tracked files.

Usage:
  python deploy.py              # Deploy files changed in the last commit
  python deploy.py --all        # Deploy all tracked files
  python deploy.py --dry-run    # Show what would be uploaded without uploading
"""

import sys
import os
import subprocess
import argparse

# Add project root to path so we can import the config
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import deploy_config as config


def get_changed_files():
    """Get files changed in the most recent commit vs its first parent."""
    try:
        result = subprocess.run(
            ["git", "diff", "--name-only", "--diff-filter=ACMR", "HEAD~1", "HEAD"],
            capture_output=True, text=True, check=True,
            cwd=os.path.dirname(os.path.abspath(__file__))
        )
        files = [f.strip() for f in result.stdout.strip().split("\n") if f.strip()]
        return files
    except subprocess.CalledProcessError:
        print("[DEPLOY] Could not determine changed files from git. Use --all to deploy everything.")
        return []


def get_deleted_files():
    """Get files deleted in the most recent commit."""
    try:
        result = subprocess.run(
            ["git", "diff", "--name-only", "--diff-filter=D", "HEAD~1", "HEAD"],
            capture_output=True, text=True, check=True,
            cwd=os.path.dirname(os.path.abspath(__file__))
        )
        files = [f.strip() for f in result.stdout.strip().split("\n") if f.strip()]
        return files
    except subprocess.CalledProcessError:
        return []


def get_all_tracked_files():
    """Get all git-tracked files."""
    result = subprocess.run(
        ["git", "ls-files"],
        capture_output=True, text=True, check=True,
        cwd=os.path.dirname(os.path.abspath(__file__))
    )
    files = [f.strip() for f in result.stdout.strip().split("\n") if f.strip()]
    return files


def get_vendor_files():
    """Walk vendor/ directory and return all files (not git-tracked)."""
    project_root = os.path.dirname(os.path.abspath(__file__))
    vendor_dir = os.path.join(project_root, "vendor")
    if not os.path.isdir(vendor_dir):
        print("[DEPLOY] vendor/ not found. Run 'composer install' first.")
        return []
    files = []
    for root, dirs, filenames in os.walk(vendor_dir):
        for f in filenames:
            full = os.path.join(root, f)
            rel = os.path.relpath(full, project_root).replace("\\", "/")
            files.append(rel)
    return files


# Files/directories to never upload
EXCLUDE_PATTERNS = [
    ".git",
    ".github",
    ".claude",
    ".env",
    "node_modules",
    "deploy.py",
    "deploy_config.py",
    "deploy_config.sample.py",
    "CLAUDE.md",
    "DOCKER.md",
    "TESTING.md",
    "docker-compose",
    "Dockerfile",
    "docker/",
    "README.md",
    "CHILD_APP_SPEC.md",
    "playwright-report",
    "test-results",
    "tests/",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    ".gitignore",
    "cert/",
    "install/",
]


def should_exclude(filepath):
    """Check if a file should be excluded from deployment."""
    for pattern in EXCLUDE_PATTERNS:
        if filepath.startswith(pattern) or ("/" + pattern) in filepath:
            return True
        if filepath == pattern:
            return True
    return False


def ensure_remote_dir(sftp, remote_path):
    """Recursively create remote directories if they don't exist."""
    dirs_to_create = []
    current = remote_path
    while current and current != "." and current != "/":
        try:
            sftp.stat(current)
            break
        except FileNotFoundError:
            dirs_to_create.append(current)
            current = os.path.dirname(current).replace("\\", "/")
        except Exception:
            break

    for d in reversed(dirs_to_create):
        try:
            sftp.mkdir(d)
            print(f"  [DIR]  {d}")
        except Exception:
            pass


def deploy(files_to_upload, files_to_delete=None, dry_run=False):
    """Deploy files via SFTP."""
    try:
        import paramiko
    except ImportError:
        print("[DEPLOY] ERROR: paramiko is required. Install with: pip install paramiko")
        sys.exit(1)

    files_to_upload = [f for f in files_to_upload if not should_exclude(f)]
    if files_to_delete:
        files_to_delete = [f for f in files_to_delete if not should_exclude(f)]
    else:
        files_to_delete = []

    if not files_to_upload and not files_to_delete:
        print("[DEPLOY] No files to deploy.")
        return

    print(f"[DEPLOY] {len(files_to_upload)} file(s) to upload, {len(files_to_delete)} file(s) to delete")

    if dry_run:
        print("\n[DRY RUN] Would upload:")
        for f in files_to_upload:
            print(f"  + {f}")
        if files_to_delete:
            print("\n[DRY RUN] Would delete:")
            for f in files_to_delete:
                print(f"  - {f}")
        return

    transport = paramiko.Transport((config.SFTP_HOST, config.SFTP_PORT))
    transport.connect(username=config.SFTP_USER, password=config.SFTP_PASS)
    sftp = paramiko.SFTPClient.from_transport(transport)

    try:
        if config.REMOTE_ROOT and config.REMOTE_ROOT != ".":
            sftp.chdir(config.REMOTE_ROOT)

        uploaded = 0
        skipped = 0
        failed = 0

        for filepath in files_to_upload:
            local_path = os.path.join(config.LOCAL_ROOT, filepath)
            remote_path = filepath.replace("\\", "/")

            if not os.path.isfile(local_path):
                print(f"  [SKIP] {filepath} (not found locally)")
                continue

            local_size = os.path.getsize(local_path)

            try:
                remote_dir = os.path.dirname(remote_path).replace("\\", "/")
                if remote_dir:
                    ensure_remote_dir(sftp, remote_dir)

                # Skip upload if remote file exists with same size
                try:
                    remote_stat = sftp.stat(remote_path)
                    if remote_stat.st_size == local_size:
                        skipped += 1
                        continue
                except FileNotFoundError:
                    pass

                sftp.put(local_path, remote_path)
                print(f"  [OK]   {filepath}")
                uploaded += 1
            except Exception as e:
                print(f"  [FAIL] {filepath}: {e}")
                failed += 1

        deleted = 0
        for filepath in files_to_delete:
            remote_path = filepath.replace("\\", "/")
            try:
                sftp.remove(remote_path)
                print(f"  [DEL]  {filepath}")
                deleted += 1
            except FileNotFoundError:
                pass
            except Exception as e:
                print(f"  [FAIL] Could not delete {filepath}: {e}")

        print(f"\n[DEPLOY] Done: {uploaded} uploaded, {skipped} unchanged, {deleted} deleted, {failed} failed")

    finally:
        sftp.close()
        transport.close()


def main():
    parser = argparse.ArgumentParser(description="Deploy admin to server via SFTP")
    parser.add_argument("--all", action="store_true", help="Deploy all tracked files + vendor")
    parser.add_argument("--vendor", action="store_true", help="Deploy only vendor/ directory")
    parser.add_argument("--with-install", action="store_true", help="Include install/ directory (first-time setup only)")
    parser.add_argument("--dry-run", action="store_true", help="Show what would be deployed")
    args = parser.parse_args()

    if args.with_install and "install/" in EXCLUDE_PATTERNS:
        EXCLUDE_PATTERNS.remove("install/")
        print("[DEPLOY] Including install/ directory for first-time setup")

    print(f"[DEPLOY] Deploying admin to {config.SFTP_HOST}...")

    if args.vendor:
        files = get_vendor_files()
        deploy(files, dry_run=args.dry_run)
    elif args.all:
        files = get_all_tracked_files() + get_vendor_files()
        deploy(files, dry_run=args.dry_run)
    else:
        changed = get_changed_files()
        deleted = get_deleted_files()
        deploy(changed, deleted, dry_run=args.dry_run)


if __name__ == "__main__":
    main()
