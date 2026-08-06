# Monitor

Monitor is a self-hosted Laravel 13 application for website availability, response-time tracking, incident notifications, and public status pages.

## Stack

- Laravel 13 with Blade, Livewire, Flux UI, and Tailwind CSS
- MySQL 8.4
- Redis 7.4
- Laravel Horizon and Scheduler
- SMTP notifications
- Nginx and PHP 8.4

## Local setup

### 1. Configure the environment

Create the local environment file:

```bash
cp .env.example .env
```

Generate an application key:

```bash
docker run --rm php:8.4.23-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Copy the generated value into `APP_KEY` and configure the passwords and SMTP credentials in `.env`. For local HTTP access, use:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_PORT=8000
REGISTRATION_ENABLED=true
SESSION_SECURE_COOKIE=false
TRUSTED_PROXIES=
```

Keep these Docker service hosts:

```dotenv
DB_HOST=mysql
REDIS_HOST=redis
```

### 2. Start the application

Build and start Laravel, MySQL, and Redis:

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d --build
```

Run the migrations:

```bash
docker compose exec app php artisan migrate
```

Open [http://localhost:8000](http://localhost:8000).

The local stack also provides these development panels:

- phpMyAdmin: [http://localhost:8080](http://localhost:8080). Sign in with the `DB_USERNAME` and `DB_PASSWORD` values from `.env`.
- Mailpit: [http://localhost:8025](http://localhost:8025). Emails sent by the application are captured here instead of being delivered externally.

The default panel ports can be changed in `.env`:

```dotenv
PHPMYADMIN_PORT=8080
MAILPIT_WEB_PORT=8025
MAILPIT_SMTP_PORT=1025
```

Register the first administrator at [http://localhost:8000/register](http://localhost:8000/register). Then set:

```dotenv
REGISTRATION_ENABLED=false
```

Apply the change by recreating the application container:

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d --force-recreate app
```

### 3. Useful commands

```bash
docker compose ps
docker compose logs -f app
docker compose exec app php artisan about
docker compose exec app php artisan horizon:status
docker compose exec app php artisan migrate:status
```

Rebuild the application after changing PHP or frontend source files:

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d --build app
```

Stop the local environment:

```bash
docker compose -f compose.yaml -f compose.local.yaml down
```

Add `--volumes` only when you intentionally want to delete the local MySQL, Redis, and uploaded favicon data:

```bash
docker compose -f compose.yaml -f compose.local.yaml down --volumes
```

## Production deployment

### 1. Configure the environment

Create the production environment from `.env.example` or add the same variables in Dockploy. Set at least:

- `APP_KEY`
- `APP_URL`
- `DB_PASSWORD`
- `MYSQL_ROOT_PASSWORD`
- `REDIS_PASSWORD`
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS`
- `HORIZON_ALLOWED_EMAILS`
- `REGISTRATION_ENABLED`
- `TRUSTED_PROXIES`

Production values must include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://monitor.example.com
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=172.20.0.0/16
```

`TRUSTED_PROXIES` accepts a comma-separated list of proxy IP addresses or CIDR ranges. The value above is only an example; use the narrowest network that contains the reverse proxy for your installation. Leave it empty when accessing Monitor directly without a reverse proxy.

Use `MAIL_SCHEME=smtp` with port `587` for STARTTLS or `MAIL_SCHEME=smtps` with port `465` for implicit TLS.

### 2. Create the administrator

For the first deployment, set:

```dotenv
REGISTRATION_ENABLED=true
```

Deploy the application, visit `/register`, and create the administrator account. Immediately change the variable to:

```dotenv
REGISTRATION_ENABLED=false
```

Redeploy the `app` service. The registration page, registration endpoint, and registration links will no longer be available.

### 3. Deploy with Dockploy

1. Connect the GitHub repository to Dockploy.
2. Select Docker Compose deployment and use `compose.yaml`.
3. Add the production environment variables.
4. Deploy the stack.
5. Route the domain to the `app` service on port `8080`.
6. Run the migrations from the application container:

```bash
php artisan migrate --force
```

The application health endpoint is `/up`.

Dokploy terminates HTTPS at Traefik and forwards requests to the application over HTTP. Set `TRUSTED_PROXIES` to the CIDR of the isolated Docker network shared with Traefik so Laravel accepts its forwarded protocol and client IP. You can inspect a network with `docker network inspect <network-name>`. Keep the `app` service behind Traefik instead of publishing port `8080` directly, and set `APP_URL` to the public HTTPS URL. Wildcard values such as `TRUSTED_PROXIES=*` are rejected.

### 4. Verify the deployment

From the server project directory:

```bash
docker compose ps
docker compose logs --tail=100 app
```

From the Dockploy `app` service terminal:

```bash
php artisan about
php artisan horizon:status
php artisan migrate:status
php artisan queue:failed
```

Submit a subscription from a public status page to verify SMTP delivery and the Horizon queue.

## Application processes

The `app` container supervises:

- Nginx
- PHP-FPM
- Laravel Horizon
- Laravel Scheduler

Horizon processes website checks and notifications. Scheduler dispatches checks according to each website's configured interval.

Scheduled maintenance:

| Command | When | Purpose |
|---|---|---|
| `monitors:dispatch-due` | every minute | Reserve due websites and queue their checks |
| `horizon:snapshot` | every 5 minutes | Record queue metrics for the Horizon dashboard |
| `monitors:report-stale` | hourly | Warn when an active website stopped producing checks |
| `monitors:roll-up-checks` | 01:30 | Aggregate recent checks into daily uptime stats |
| `monitors:prune-checks` | 02:00 | Delete raw checks past `MONITOR_CHECK_RETENTION_DAYS` |
| `model:prune` | 02:30 | Delete unconfirmed subscription requests |
| `notifications:prune` | 02:45 | Delete read in-app notifications past their retention |
| `monitors:dispatch-favicon-refresh` | weekly | Re-fetch every website's favicon |

The roll-up runs before the pruner on purpose: it is what preserves a day's
uptime once the raw checks behind it are deleted, which is what lets status page
history outlive `MONITOR_CHECK_RETENTION_DAYS`.

### Checks and redirects

A check follows up to five redirects. Every hop is revalidated independently —
scheme, port, credentials, and the resolved IP address — so a public URL cannot
redirect a check into a private network. The expected status code is compared
against the *final* response, which is why a site that moves apex to www, or HTTP
to HTTPS, is reported on its real status rather than on its redirect.

When a hostname resolves to several addresses, they are tried in turn (IPv4
first) until one answers, so a single unreachable address on a multi-homed host
does not raise a false outage.

## Updating production

Pull the new release and run:

```bash
docker compose build --pull app
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan horizon:status
```

After upgrading to a release that introduces daily uptime stats, build them once
so status page history covers your existing data instead of waiting for the first
nightly run:

```bash
docker compose exec app php artisan monitors:roll-up-checks --backfill
```

## Controlled dependency updates

Composer, npm, PHP, Node.js, Composer, MySQL, Redis, and the production image dependencies use exact versions. Docker base images are also pinned by digest. Updates are intentional: change the required version, regenerate the relevant lock file, update image digests when applicable, rebuild the production image, and run all quality checks before committing.

Dependabot only proposes GitHub Actions updates for review. It does not merge or deploy them automatically.

## Persistent data

Docker stores MySQL, Redis, and public favicon data in named volumes. Back up the MySQL volume regularly and keep production credentials in Dockploy's environment management.

Never commit `.env`, `APP_KEY`, or production credentials. Do not use `docker compose down --volumes` in production.

## Quality checks

GitHub Actions runs the automated test suite with isolated MySQL and Redis services.

The suite is destructive: `RefreshDatabase` drops every table. Two independent
guards keep it away from real data, and both matter:

- `app/phpunit.xml` pins `DB_DATABASE`, `MAIL_MAILER`, the queue, and the Redis
  keyspace with `force="true"` **and** matching `<server>` entries. The `<server>`
  half is the one that actually wins, because `variables_order` publishes the
  environment to `$_SERVER` and Dotenv reads that before `$_ENV` or `getenv()`.
- `Tests\TestCase` refuses to run against any database whose name does not end in
  `_testing`.

So the suite always targets `monitor_testing`. Create that database once before
the first manual run; connection host and credentials remain overridable via the
environment for local, Docker, or CI use.

From an environment with PHP 8.4, MySQL, Redis, and Node.js available:

```bash
cd app
php artisan test --compact
vendor/bin/pint --test --format agent
php vendor/bin/phpstan analyse --memory-limit=1G
npm run build
```

### Running the suite against the local Docker stack

There is one supported way to point a host PHP install (the same PHP used by
CI, not a throwaway container) at the project's own MySQL/Redis: `compose.local.yaml`
publishes both to `127.0.0.1`, matching the pattern it already uses for `app`.

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d mysql redis
docker exec monitor-mysql-1 mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "CREATE DATABASE IF NOT EXISTS monitor_testing"

cd app
DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=monitor DB_PASSWORD="$DB_PASSWORD" \
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 REDIS_PASSWORD="$REDIS_PASSWORD" \
  php artisan test --compact
```

`$MYSQL_ROOT_PASSWORD`, `$DB_PASSWORD`, and `$REDIS_PASSWORD` are the values from
your `.env` (see `.env.example`). Do not install extra PHP images or ad-hoc
network-bridging containers for this — if host PHP cannot reach a service the
compose stack already runs, publish that service's port here instead.
