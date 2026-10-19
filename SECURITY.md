# Security

## Reporting a vulnerability

Please report privately through GitHub: **Security → Report a vulnerability** on
this repository. Don't open a public issue for a security problem.

Include what you found, how to reproduce it, and what an attacker gains. We aim to
acknowledge within 3 working days and to keep you updated until it is fixed.

Especially in scope: authentication, sessions, two-factor and SSO, shard routing
(one user reaching another's data), the admin API and MCP endpoints, and anything
that exposes configuration or service keys.

Please test against your own install, not against live sites built on this core,
and don't run load or denial-of-service tests.
