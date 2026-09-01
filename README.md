# VIT Vendor Portal

A Laravel + Filament application that lets vendors manage their product catalogs, submit them for review, and have approved catalogs uploaded to Value Innovation Tech (VIT). Admins review, approve/reject, and monitor upload delivery from a Filament admin panel.

## Tech Stack

- PHP 8.2+, Laravel 12
- Filament 5 (admin panel) + `bezhansalleh/filament-shield` (roles/permissions)
- Livewire 3 (vendor-facing catalog forms/upload UI)
- `phpoffice/phpspreadsheet` for generating/reading catalog Excel files
- `league/flysystem-sftp-v3` for delivering files over SFTP
- Vite, Tailwind CSS 4, Tom Select

## Requirements

- PHP >= 8.2 with extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `zip` (required by PhpSpreadsheet/Filament)
- Composer 2
- Node.js 18+ and npm
- A database: SQLite (default, zero-config) or MySQL/PostgreSQL
- A queue worker process (catalog processing, exports, and VIT uploads all run as queued jobs)

### php.ini settings

Vendors can upload catalog files (CSV/XLSX/XLS) up to **50MB**, and catalog item images up to **8MB** each. Set the following in `php.ini` (or your Herd/Valet/PHP-FPM pool config) to at least:

| Setting               | Minimum | Why                                                                                                               |
| --------------------- | ------- | ----------------------------------------------------------------------------------------------------------------- |
| `upload_max_filesize` | `50M`   | Matches the catalog upload validation limit (`max:51200` KB)                                                      |
| `post_max_size`       | `55M`   | Must be larger than `upload_max_filesize` to allow for form overhead                                              |
| `memory_limit`        | `512M`  | Large XLSX files are parsed in memory when inspecting columns and generating exports                              |
| `max_execution_time`  | `300`   | Column inspection and export generation raise this per-request already, but the php.ini floor should not be lower |

> Local development via [Laravel Herd](https://herd.laravel.com) generally ships with generous defaults, but confirm these values in production (`php --ini` to find the loaded `php.ini`).

## Setup

```bash
# 1. Clone and install PHP dependencies
composer install

# 2. Copy the environment file and generate an app key
cp .env.example .env
php artisan key:generate

# 3. Create the SQLite database file (default DB_CONNECTION=sqlite)
touch database/database.sqlite

# 4. Run migrations and seed reference data (hierarchies, unit of measure, etc.)
php artisan migrate --seed

# 5. Install JS dependencies and build assets
npm install
npm run build   # or `npm run dev` while developing

# 6. Link the public storage disk (catalog uploads/exports/images)
php artisan storage:link

# 7. Create the default admin user for the Filament admin panel
php artisan elink:create-user --name='John Doe' --email=admin@example.com --password=password
```

Or run everything in one shot with the Composer script:

```bash
composer setup
```

### Running the app

```bash
composer dev
```

This starts, in parallel: the PHP dev server (`php artisan serve`), a queue worker (`php artisan queue:listen`), `php artisan pail` for live logs, and the Vite dev server.

If running services individually instead:

```bash
php artisan serve
php artisan queue:listen --tries=1
npm run dev
```

### Scheduler

`vit:retry-failed-uploads` runs hourly (see [routes/console.php](routes/console.php)) to retry failed VIT uploads at the intervals configured by `VIT_RETRY_AFTER_HOURS`. Make sure the Laravel scheduler is running in any long-lived environment:

```bash
* * * * * php /path-to-project/artisan schedule:run >> /dev/null 2>&1
```

## Key Environment Variables

In addition to the standard Laravel `.env` values, this app uses:

| Variable                             | Purpose                                                                                            |
| ------------------------------------ | -------------------------------------------------------------------------------------------------- |
| `VIT_ADMIN_EMAIL`                    | Receives "catalog ready for review" notifications                                                  |
| `VIT_GATEWAY_EMAIL`                  | Receives notifications for new/unrecognized hierarchy paths, commodity types, and units of measure |                                                          |
| `VIT_RETRY_AFTER_HOURS`              | Comma-separated hours after which failed uploads are auto-retried (default `1,6,24`)               |
| `VIT_EXCEL_IMAGE_SIZE`               | Square pixel size images are resized to when embedded in the export Excel file                     |
| `VIT_EXCEL_IMAGE_PADDING`            | Padding (px) between stacked images in the export Excel file                                       |
| `CATALOG_PROCESSING_TIMEOUT_MINUTES` | Minutes before a stuck catalog upload job is considered stale and reclaimable                      |
| `ALLOW_PERIODIC_DB_RESET`     | When `true`, `elink:reset-database` runs weekly (via the scheduler) to wipe and reseed the database. Demo/staging only — leave unset in production |

## Testing

```bash
composer test
# or
php artisan test
```

## Admin Panel

The Filament admin panel is available at `/admin` (see [app/Providers](app/Providers)). Use `bezhansalleh/filament-shield` to manage roles/permissions; run `php artisan shield:install` if setting up roles for the first time on a fresh database.
