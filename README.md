# Dream Hosting Server Monitor

An open-source, self-hosted control panel for monitoring Linux and Windows servers. The Laravel application stores resource samples and exposes a small HTTPS API for outbound-only agents. It has no commercial subscription system and does not execute remote commands on monitored hosts.

## Project purpose

Dream Hosting Server Monitor is an independent, non-commercial open-source project for people who operate their own servers. The project is seeking community infrastructure sponsorship to host a small, read-only public demo and its documentation. Any public demo will use synthetic server data and will not expose administrator access or real host details.

## Technology stack

- **Control panel and API:** Laravel 13, PHP 8.3, Blade, Bootstrap 5 and Chart.js 4, built with Vite 8.
- **Database:** MySQL 8.4 LTS.
- **Monitoring agents:** Python for Linux and PowerShell for Windows.
- **Agent communication:** HTTPS JSON API with one-time enrollment and revocable per-server credentials; agents make outbound connections only.

## MVP status

| Capability | Status |
| --- | --- |
| Admin login and server registration | Implemented |
| One-time agent enrollment and revocable per-server credentials | Implemented |
| CPU, memory, disk and online/offline monitoring | Implemented |
| Linux Python and Windows PowerShell agents | Initial implementation; validate on target OS versions before broad use |
| Dashboard and 24-hour resource chart | Implemented |
| Configurable metric retention | Implemented (30 days by default) |
| Threshold alerts, email/webhook delivery, website/SSL checks | Planned; not implemented |
| Public hosted demo and screenshots | Not published yet |

This is an early MVP. Contributions and issue reports are welcome. Do not expose an unreviewed instance to the public internet.

## Requirements

- PHP 8.3 with OpenSSL, PDO MySQL, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo and cURL.
- Composer 2.8 or newer.
- MySQL 8.4 LTS (or a compatible MySQL release); the local example uses `127.0.0.1:3307`.
- Node.js 22.12+ and npm for Vite 8 asset builds.
- Python 3.10+ on monitored Linux hosts, or Windows PowerShell 5.1+ on monitored Windows hosts.

## Local setup

See [docs/installation.md](docs/installation.md) for Windows and Linux setup, database provisioning, secure admin creation, agent enrollment and VPS deployment. In short:

1. Create a dedicated MySQL database and app user restricted to that database.
2. Install PHP and Node dependencies; copy `.env.example` to `.env` only if `.env` does not already exist.
3. Set the DB connection, generate `APP_KEY`, build the assets and apply additive migrations.
4. Create the first administrator using `php artisan monitor:create-admin`.
5. Run the app, register a server and enroll its matching agent.

Never commit `.env`, database passwords, enrollment tokens or agent credentials. Never configure Laravel with the MySQL root account. Inspect existing environments before running migrations.

## Agent security model

- Agents initiate outbound HTTPS requests; the monitor does not open inbound agent ports or provide remote shell/command execution.
- Enrollment tokens are random, one-use, expire after 30 minutes and are stored as SHA-256 hashes. The resulting per-server 256-bit credential is returned once; only its hash is stored by the app.
- Agents reject non-HTTPS monitor URLs except loopback development URLs and retain operating-system certificate validation defaults.
- Credentials can be revoked by disabling a server. Login and agent API routes are rate-limited.
- Windows agent credentials use a local ACL; Linux credentials use owner-only file permissions. Run agents under dedicated, unprivileged service accounts.
- For VPS deployment, use HTTPS, `APP_DEBUG=false`, a unique `APP_KEY`, secure session cookies, restricted SSH, database backups and a firewall that exposes only required web traffic.

## Data and retention

The default agent interval is one minute. Metric batches older than `MONITOR_METRICS_RETENTION_DAYS` (30 days by default) are pruned daily by Laravel's scheduler. Related samples and disk rows are removed by database foreign-key cascades. Back up the database before changing schema or retention policy.

## Roadmap

1. Validate both agents on supported Linux distributions and Windows versions; harden the first VPS deployment.
2. Add threshold rules and reliable email/webhook delivery with incident deduplication.
3. Add automated coverage for enrollment, authorization, idempotent ingestion, retention and status transitions.
4. Add opt-in synthetic demo data, screenshots and a public read-only demo that contains no real host metadata.
5. Add documented upgrade, backup/restore and contribution workflows; review retention/query performance at larger server counts.

## Contributing and security

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a change. Report suspected vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
