# TimerAdmin

TimerAdmin is a Laravel 11 style admin portal with a Svelte UI for managing TimerApp licenses, public news, and downloadable app updates.

## What is included

- Public `Home` page with admin-posted news.
- Admin login page.
- Admin dashboard with a Dark Fusion theme.
- Unique 12-digit license generation.
- License list with:
  - License key
  - Creation date
  - Expiry date
  - PC name
  - Status
- Status monitoring rules:
  - `Available`: license has not been used and no PC name is assigned yet.
  - `Active`: the device sent a heartbeat within the configured active window.
  - `Inactive`: the license is assigned to a PC but the device has not checked in recently.
  - `Expired`: the expiry date has passed.
- CSV export for all licenses.
- TimerApp update uploads for `.zip`, `.exe`, and `.msi` files.
- API endpoints so TimerApp clients can activate a license, send heartbeats, and detect the latest uploaded update.
- MySQL-ready environment and migrations.

## Main tables

- `users`
- `licenses`
- `news_posts`
- `app_updates`

## Local setup

This Codex environment did not have `php` or `composer` installed, so the project was scaffolded as source code only. To run it locally on your machine:

1. Install PHP 8.2+, Composer, Node.js, and MySQL.
2. Copy `.env.example` to `.env`.
3. Update the MySQL settings in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=timer_admin
DB_USERNAME=root
DB_PASSWORD=
```

4. Install backend dependencies:

```bash
composer install
```

5. Generate the application key:

```bash
php artisan key:generate
```

6. Run migrations and seed the admin user:

```bash
php artisan migrate --seed
```

7. Create the public storage symlink for uploaded updates:

```bash
php artisan storage:link
```

8. Install frontend dependencies and build or run Vite:

```bash
npm install
npm run dev
```

9. Start Laravel:

```bash
php artisan serve
```

## Seeded admin login

- Email: `admin@timerapp.local`
- Password: `changeme123`

Change this password after the first login.

## TimerApp API

### Activate a license

`POST /api/v1/licenses/activate`

```json
{
  "license_key": "123456789012",
  "pc_name": "OFFICE-PC-01",
  "app_version": "1.0.0"
}
```

### Send a heartbeat

`POST /api/v1/licenses/heartbeat`

```json
{
  "license_key": "123456789012",
  "pc_name": "OFFICE-PC-01",
  "app_version": "1.0.0"
}
```

### Check for the latest TimerApp update

`GET /api/v1/updates/latest?current_version=1.0.0`

If a newer active release exists, the response includes metadata and a download URL.

## Configurable monitoring window

The dashboard considers a device `Active` when it has checked in within the configured number of minutes:

```env
TIMER_ACTIVE_WINDOW_MINUTES=10
```

## Key files

- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Api/TimerAppController.php`
- `resources/js/pages/admin/DashboardPage.svelte`
- `resources/js/pages/HomePage.svelte`
- `resources/css/app.css`
