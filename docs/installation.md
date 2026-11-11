# Installation and deployment

These instructions describe the current MVP. Review configuration and take a database backup before upgrades. Do not copy a production `.env` into a public repository or paste secrets into issue reports.

## Windows development setup

The local workstation was prepared against PHP 8.3, Composer, Node.js 22 and MySQL 8.4. Use explicit PHP/Composer paths if another PHP version is first on `PATH`.

1. Install PHP 8.3, Composer, Node.js 22.12+ and MySQL 8.4. Enable the PHP extensions listed in the README.
2. Create database `dream_hosting_monitor` and a separate account `dream_hosting_monitor_app` restricted to that database. Use a generated password in a trusted database administration UI; do not put it into a shell command or this guide.
3. In PowerShell, from the project directory, create the environment file only when it is absent:

   ```powershell
   if (-not (Test-Path .env)) { Copy-Item .env.example .env }
   ```

4. Edit `.env` locally: set `APP_NAME`, `APP_URL`, `DB_HOST=127.0.0.1`, `DB_PORT=3307`, `DB_DATABASE=dream_hosting_monitor`, the dedicated `DB_USERNAME` and its password. Keep `.env` private.
5. Install dependencies and initialize the app:

   ```powershell
   composer install
   php artisan key:generate
   npm ci
   npm run build
   php artisan migrate
   php artisan monitor:create-admin
   php artisan serve --host=127.0.0.1
   ```

   Use `npm ci` only when the lockfile and dependencies can be fetched. The repository `.npmrc` disables package lifecycle scripts; keep that protection enabled unless a dependency script has been reviewed.
6. Open `http://127.0.0.1:8000`, sign in with the administrator created interactively, register a server and enroll the matching agent. Loopback HTTP is permitted only for local agent testing; use HTTPS elsewhere.

Do not run `migrate:fresh`, `db:wipe` or a destructive reset on a database with data. For deployment, run `php artisan migrate --force` only after backup and review of pending migrations.

## Linux VPS deployment

The control panel is intended to run on an Ubuntu/Debian Linux VPS behind Nginx and HTTPS. Keep MySQL bound to loopback/private networking and use a dedicated least-privilege app account. Expose only HTTPS publicly and restrict SSH to maintainers. Agents make outbound HTTPS requests, so no inbound agent firewall rule is needed.

1. Provision a supported PHP 8.3 FPM environment, required extensions, Composer, Node.js for asset builds and MySQL 8.4 (local or a private database service). Create an app database/user with access only to that database.
2. Deploy a tagged release into a directory outside the web root. Point Nginx's document root only at the release's `public/` directory. Keep `.env`, `storage/`, `vendor/` and database data out of the public document root.
3. Create a new `.env` from `.env.example` only when absent. Set production values: `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, a unique `APP_KEY`, dedicated DB credentials, `SESSION_SECURE_COOKIE=true` and suitable `MONITOR_METRICS_RETENTION_DAYS`. The example uses file-backed sessions for a single VPS; use a shared session store when deploying multiple app nodes.
4. Build assets in the release, review pending migrations, back up the DB, then run `php artisan migrate --force`. Create the administrator interactively with `php artisan monitor:create-admin`; do not seed a default account.
5. Give the web/app service account write access to Laravel's `storage/` and `bootstrap/cache/`. Enable the scheduler once per minute:

   ```cron
   * * * * * cd /srv/dream-hosting-server-monitor/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

   The scheduler marks missed agents offline and prunes old samples. The current MVP does not require a queue worker.
6. Issue and renew a valid TLS certificate, redirect HTTP to HTTPS, restrict administrator access appropriately, and test restoring a database backup before onboarding real hosts.

## Agent enrollment

The dashboard issues a one-time enrollment token when a server is created. It expires after 30 minutes and is displayed only on the creation response. Copy it directly into the monitored host's protected environment for first enrollment, then remove it. The agent stores its long-lived credential locally; do not put that credential in command-line arguments, service definitions, Git or support logs.

Linux and Windows run instructions and service-account guidance are in [../agents/README.md](../agents/README.md). The default sample interval is one minute. Disabling a server revokes its active agent credentials.

## VPS demo checklist

The repository does not currently publish a hosted demo or screenshots. Before offering one, deploy a separate demo instance, use synthetic host labels/data only, create a read-only demo account, remove real credentials and host metadata, set modest retention/sample rates, and verify that the account cannot register or disable servers. Never expose an administrator account or real monitored host in a public demo.
