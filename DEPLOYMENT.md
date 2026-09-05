# DigitoFina Deployment Guide

## Server Requirements

- PHP 8.3+
- Composer 2+
- MySQL 8+
- Node.js 20+
- Web server pointing to the `public` directory
- Writable `storage` and `bootstrap/cache` directories
- Queue worker for database-backed jobs

## First-Time Deployment

```bash
git clone https://github.com/DigitoMove/digitofina.git
cd digitofina

composer install --no-dev --optimize-autoloader
npm ci
npm run build

cp .env.production.example .env
php artisan key:generate
php artisan migrate --force
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Update `.env` with the production database, mail, domain, and generated `APP_KEY`.

## Release Update

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Queue Worker

Run this under Supervisor, systemd, or your hosting platform's process manager:

```bash
php artisan queue:work --tries=3 --timeout=90
```

## Scheduler

Run Laravel's scheduler every minute from the server cron. The application will apply overdue loan penalties once per day at midnight in the configured `APP_TIMEZONE`.

```cron
* * * * * cd /path/to/digitofina && php artisan schedule:run >> /dev/null 2>&1
```

Only add this cron entry on one application server. For a process manager or Laravel Cloud, run `php artisan schedule:work` as the dedicated scheduler process instead.

## Web Server

The document root must be:

```text
/path/to/digitofina/public
```

Do not expose the project root, `.env`, `storage`, or `vendor` directories publicly.

## Health Check

Use this endpoint for uptime checks:

```text
/health
```

It returns a small JSON response when the app is booting correctly.

## Security Checklist

- `APP_ENV=production`
- `APP_DEBUG=false`
- `SESSION_ENCRYPT=true`
- `SESSION_SECURE_COOKIE=true` when using HTTPS
- Strong database password
- `.env` never committed
- Queue worker running
- Daily database backup configured
- HTTPS enabled
- File permissions restricted to the deploy user
