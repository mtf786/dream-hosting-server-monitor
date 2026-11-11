# Security policy

## Supported versions

Security fixes target the latest maintained release. This project is in early MVP development and has no published stable release.

## Reporting a vulnerability

Do not report exploitable vulnerabilities in a public issue. Use GitHub's private vulnerability reporting for this repository, or contact the maintainer through the private contact method listed on the repository profile. Include the affected version/commit, impact and safe reproduction steps. Do not include real server credentials or host data.

The maintainer will acknowledge the report, investigate it, coordinate a fix and credit the reporter if desired. Please allow time for a fix before public disclosure.

## Operational guidance

Use HTTPS for non-local agents, keep `APP_DEBUG=false` in production, protect `.env` and backups, use a least-privilege database account, keep agent credentials private, restrict administrator access, and update dependencies deliberately. Disabling a server revokes its active agent credentials.
