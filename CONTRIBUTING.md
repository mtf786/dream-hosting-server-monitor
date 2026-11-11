# Contributing

Thanks for helping improve Dream Hosting Server Monitor. The project is an MIT-licensed, self-hosted tool; contributions should preserve user control, privacy and secure defaults.

## Before opening a change

- Search existing issues and explain the problem and expected behavior.
- Keep changes focused. Avoid adding a hosted-service dependency or paid subscription feature to the core project.
- Never include real `.env` values, credentials, enrollment tokens, IP addresses, hostnames or customer data.
- For agent changes, state the tested OS/version and whether elevated privileges are required. Agents should remain outbound-only and must not add remote command execution.
- For migrations, include a reversible migration, indexes that match query patterns, and a note about data volume/locking implications.

## Development workflow

1. Follow [docs/installation.md](docs/installation.md) and use a separate development database.
2. Add or update focused automated tests for behavior and security boundaries.
3. Run relevant PHP checks and tests, plus `npm run build` for frontend changes.
4. Review your diff for accidental secrets, generated files and unrelated changes.
5. Open a pull request describing the change, verification performed, supported environments and follow-up work.

Please keep discussions respectful. Report security issues privately as described in [SECURITY.md](SECURITY.md).
