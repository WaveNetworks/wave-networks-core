#!/bin/bash
set -e

# Ensure files directory exists
mkdir -p "${FILES_LOCATION:-/var/files}/home"
chown -R www-data:www-data "${FILES_LOCATION:-/var/files}"

# Install composer deps into the vendor named volume (first run only).
# Source is mounted :ro so vendor/ is a writable named volume overlay.
if [ -f /var/www/public_html/admin/composer.json ] && [ ! -f /var/www/public_html/admin/vendor/autoload.php ]; then
    echo "Installing admin composer dependencies..."
    cd /var/www/public_html/admin && composer install --no-dev --optimize-autoloader
fi

# Start Apache (CMD passed from Dockerfile)
exec "$@"
