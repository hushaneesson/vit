# VIT Vendor Portal — Server Setup & Deployment Guide

For the ops/tech team setting up production/staging servers. Current app version: see [`VERSION`](../VERSION).

## 1. Server Requirements

- PHP 8.3+ with extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `zip`
- Composer 2+
- Node.js 20+ and npm (build step only — not required at runtime)
- MySQL 8+
- Apache 2.4+ with `mod_rewrite` and `mod_proxy_fcgi` (or `mod_php`), PHP-FPM recommended
- A process manager for the queue worker (systemd, recommended)
- Cron access for the Laravel scheduler

### php.ini

| Setting               | Minimum | Why                                   |
| --------------------- | ------- | ------------------------------------- |
| `upload_max_filesize` | `50M`   | Catalog upload validation limit       |
| `post_max_size`       | `55M`   | Must exceed `upload_max_filesize`     |
| `memory_limit`        | `512M`  | Large XLSX parsing/export             |
| `max_execution_time`  | `300`   | Column inspection / export generation |

## 2. First-Time Server Setup

1. **Clone the repo** to the deployment path (e.g. `/var/www/vit`) and check out the release tag:
    ```bash
    git clone <repo-url> /var/www/vit
    cd /var/www/vit
    git checkout <release-tag>
    ```
2. **Configure the environment:**
    ```bash
    cp .env.example .env
    php artisan key:generate
    ```
    Then edit `.env`:
    - Set `APP_ENV=production` and `APP_DEBUG=false`
    - Set DB credentials
    - Set SFTP credentials (`PARTNER_SFTP_*`)
    - Set mail credentials
3. **Configure the Apache virtual host.** Create `/etc/apache2/sites-available/vit.conf` pointing at `/var/www/vit/public` as the document root, with `AllowOverride All` and a PHP handler (mod_proxy_fcgi to PHP-FPM, or mod_php). Set `LimitRequestBody` to at least `57671680` (55MB) to match the catalog upload limits. Then enable and reload:
    ```bash
    sudo a2ensite vit.conf
    sudo a2enmod rewrite proxy_fcgi
    sudo systemctl reload apache2
    ```
4. **Set up the queue worker as a systemd service.** Create `/etc/systemd/system/vit-queue-worker.service` that runs `php /var/www/vit/artisan queue:work --sleep=3 --tries=3 --max-time=3600` as the web server user, with `Restart=always`. Then:
    ```bash
    sudo systemctl daemon-reload
    sudo systemctl enable --now vit-queue-worker
    ```
5. **Add the scheduler to cron** (as the deploy user, `crontab -e`):
    ```bash
    * * * * * php /var/www/vit/artisan schedule:run >> /dev/null 2>&1
    ```
6. **Build and go live** — see the deploy checklist in section 3 below, then:
    ```bash
    php artisan storage:link
    php artisan elink:create-user --name='Admin' --email=admin@example.com --password='<set-a-real-password>'
    ```

## 3. Deploying a New Release

Run these steps, in order, on the server for every release:

1. Bump the version and pull the code:
    ```bash
    cd /var/www/vit
    git fetch --tags
    git checkout <new-release-tag>
    echo "<new-version>" > VERSION
    ```
2. Put the app into maintenance mode:
    ```bash
    php artisan down --render="errors::503" --retry=60
    ```
3. Install PHP dependencies:
    ```bash
    composer install --no-dev --optimize-autoloader --no-interaction
    ```
4. Install JS dependencies and build front-end assets:
    ```bash
    npm ci
    npm run build
    ```
5. Run database migrations:
    ```bash
    php artisan migrate --force
    ```
6. Cache framework config/routes/views/events:
    ```bash
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    ```
7. Restart the queue worker so it picks up the new code:
    ```bash
    php artisan queue:restart
    sudo systemctl restart vit-queue-worker
    ```
8. Bring the app back online:
    ```bash
    php artisan up
    ```

## 4. Versioning

- The [`VERSION`](../VERSION) file at the repo root holds the current release version (semver, e.g. `1.2.0`).
- Bump it as part of each release (step 1 above) so it's clear which version is running on the server.
- Tag releases in git to match: `git tag v1.2.0 && git push --tags`.

## 5. Key Environment Variables

| Variable                             | Purpose                                                                              |
| ------------------------------------ | ------------------------------------------------------------------------------------ |
| `VIT_ADMIN_EMAIL`                    | Receives "catalog ready for review" notifications                                    |
| `VIT_GATEWAY_EMAIL`                  | Receives notifications for new hierarchy paths, commodity types, units of measure    |
| `VIT_RETRY_AFTER_HOURS`              | Comma-separated hours after which failed uploads are auto-retried (default `1,6,24`) |
| `VIT_EXCEL_IMAGE_SIZE`               | Square pixel size images are resized to in the export Excel file                     |
| `VIT_EXCEL_IMAGE_PADDING`            | Padding (px) between stacked images in the export Excel file                         |
| `CATALOG_PROCESSING_TIMEOUT_MINUTES` | Minutes before a stuck catalog upload job is considered stale                        |
| `PARTNER_SFTP_*`                     | SFTP credentials for delivering approved catalogs to VIT                             |
| `ALLOW_PERIODIC_DB_RESET`            | **Leave unset in production.** Enables weekly demo DB reset                          |

See the main [README.md](../README.md) for local development setup.
