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

Production values must include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://monitor.example.com
SESSION_SECURE_COOKIE=true
```

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

Dokploy terminates HTTPS at Traefik and forwards requests to the application over HTTP. Monitor trusts Traefik's standard `X-Forwarded-*` headers so Laravel generates HTTPS URLs from the original request. Keep the `app` service behind Traefik instead of publishing port `8080` directly, and set `APP_URL` to the public HTTPS URL.

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

## Updating production

Pull the new release and run:

```bash
docker compose build --pull app
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan horizon:status
```

## Controlled dependency updates

Composer, npm, PHP, Node.js, Composer, MySQL, Redis, and the production image dependencies use exact versions. Docker base images are also pinned by digest. Updates are intentional: change the required version, regenerate the relevant lock file, update image digests when applicable, rebuild the production image, and run all quality checks before committing.

Dependabot only proposes GitHub Actions updates for review. It does not merge or deploy them automatically.

## Persistent data

Docker stores MySQL, Redis, and public favicon data in named volumes. Back up the MySQL volume regularly and keep production credentials in Dockploy's environment management.

Never commit `.env`, `APP_KEY`, or production credentials. Do not use `docker compose down --volumes` in production.

## Quality checks

GitHub Actions runs the automated test suite with isolated MySQL and Redis services. Manual test runs require a disposable `monitor_testing` database matching `app/phpunit.xml`; never point the test suite at production.

From an environment with PHP 8.4, MySQL, Redis, and Node.js available:

```bash
cd app
php artisan test --compact
vendor/bin/pint --test --format agent
php vendor/bin/phpstan analyse --memory-limit=1G
npm run build
```
