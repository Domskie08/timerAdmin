# TimerAdmin

TimerAdmin is a Laravel 11 + Inertia + Svelte admin portal for managing TimerApp licenses, public announcements, and downloadable desktop app updates.

This README is meant to explain how the system works in plain language, not just how to start it.

## What the system does

TimerAdmin has 3 main jobs:

1. Admins generate 12-digit license keys.
2. TimerApp clients activate a license and send heartbeats so the portal can show whether a device is active.
3. Admins publish news and upload the latest TimerApp installer package for clients to discover through the API.

There are 3 main surfaces in the app:

- Public website: `/`
- Admin area: `/admin` and `/admin/login`
- Client API: `/api/v1/...`

## Tech stack

- Backend: Laravel 11
- Frontend delivery: Inertia.js
- Frontend pages: Svelte 5
- Asset bundling: Vite
- Database: MySQL
- File storage: Laravel `public` disk
- Auth: Laravel session auth

## How the request flow works

This project is a server-driven SPA style app:

1. A browser request hits Laravel routes in `routes/web.php` or `routes/api.php`.
2. A controller loads data from Eloquent models.
3. Web controllers return an Inertia response instead of a traditional Blade page.
4. `resources/views/app.blade.php` loads the Vite bundles and mounts the Inertia app.
5. `resources/js/app.js` resolves the requested Svelte page and mounts it in the browser.
6. Shared props like the logged-in user, CSRF token, and flash messages are injected by `app/Http/Middleware/HandleInertiaRequests.php`.

API routes skip the Inertia layer and return JSON or file downloads directly.

## High-level architecture

### Public site

- Route: `GET /`
- Controller: `App\Http\Controllers\HomeController`
- Page: `resources/js/pages/HomePage.svelte`

What happens:

- The home page loads all news posts that are already published.
- Pinned posts are shown first.
- It also loads the latest active published app update, if one exists.
- Visitors can read news without logging in.
- If an admin is logged in, the home page shows a shortcut to the dashboard.

### Admin authentication

- Routes:
  - `GET /admin/login`
  - `POST /admin/login`
  - `POST /logout`
- Controller: `App\Http\Controllers\Auth\LoginController`
- Page: `resources/js/pages/auth/LoginPage.svelte`

How login works:

- Only guests can open the login page.
- Laravel validates email, password, and optional "remember me".
- `Auth::attempt()` checks the credentials.
- After login, the controller verifies `is_admin`.
- If the user is not an admin, they are immediately logged out.
- On success, the session is regenerated and the user is redirected to `/admin`.

How admin protection works:

- The `auth` middleware requires a logged-in session.
- The custom `admin` middleware (`EnsureUserIsAdmin`) aborts with `403` unless `is_admin` is true.

### Admin dashboard

- Route: `GET /admin`
- Controller: `App\Http\Controllers\Admin\DashboardController`
- Page: `resources/js/pages/admin/DashboardPage.svelte`

What the dashboard shows:

- Top-level stats for license counts and device health
- Form to create a new license
- CSV export link for all licenses
- License registry table
- Form to upload a TimerApp update package
- Form to publish a news post
- Recent news list
- Recent app update list

Important implementation detail:

- The dashboard currently loads all licenses, all news posts, and all app updates in one request.
- There is no pagination yet.

## Core workflows

### 1. Creating a license

- Route: `POST /admin/licenses`
- Controller: `App\Http\Controllers\Admin\LicenseController`
- Request validator: `StoreLicenseRequest`

How it works:

1. The admin picks a duration from the allowed options:
   - `1_month`
   - `3_months`
   - `6_months`
   - `1_year`
2. The backend generates a unique 12-digit code by joining two zero-padded random 6-digit numbers.
3. The expiration date is calculated from the creation date using `addMonthsNoOverflow()`.
4. The new record is saved in the `licenses` table.

Current duration rules come from `App\Models\License::DURATION_OPTIONS`.

### 2. Understanding license status

License status is not stored as a plain column. It is calculated dynamically in `App\Models\License::status()`.

The status rules are:

- `Expired`: the expiry date has passed
- `Available`: the license has no assigned device name
- `Active`: the license is assigned and `last_seen_at` is inside the active window
- `Inactive`: the license is assigned but has not checked in recently

The active window is controlled by:

```env
TIMER_ACTIVE_WINDOW_MINUTES=10
```

The status logic uses:

- `device_name`
- `machine_id`
- `expires_at`
- `last_seen_at`

Important detail:

- A license remains valid through the entire `expires_at` day because expiration is checked against the end of that day.

### 3. Exporting licenses

- Route: `GET /admin/licenses/export`

This downloads a CSV with:

- License key
- Creation date
- Expiry date
- PC name
- Status

### 4. Publishing news

- Route: `POST /admin/news`
- Controller: `App\Http\Controllers\Admin\NewsPostController`
- Request validator: `StoreNewsPostRequest`

How it works:

- Admin enters a title, body, optional publish date, and optional pinned flag.
- The record is stored in `news_posts`.
- The public home page only shows posts that are already published.
- Pinned posts are sorted above non-pinned posts.

Published visibility is controlled by `NewsPost::scopePubliclyVisible()`:

- `published_at` is `NULL`, or
- `published_at <= now()`

### 5. Uploading a TimerApp update

- Route: `POST /admin/updates`
- Controller: `App\Http\Controllers\Admin\AppUpdateController`
- Request validator: `StoreAppUpdateRequest`

Allowed file types:

- `.zip`
- `.exe`
- `.msi`

Max upload size:

- `102400 KB` according to the validator

How it works:

1. The uploaded file is stored on the `public` disk under `storage/app/public/updates/...`.
2. The stored filename is normalized into a timestamped slug.
3. A database transaction runs.
4. All existing `app_updates` rows are marked `is_active = false`.
5. A new `app_updates` row is created with `is_active = true`.

Important behavior:

- Only one app update is active at a time.
- The public site and API only consider updates that are both:
  - `is_active = true`
  - already published

Scheduling nuance:

- If you upload a future-dated release, older releases are still deactivated immediately.
- That means there may be a period where no update is considered live until the new `published_at` time arrives.

## TimerApp API

The API is handled by `App\Http\Controllers\Api\TimerAppController`.

Primary versioned routes:

- `POST /api/v1/licenses/activate`
- `POST /api/v1/licenses/status`
- `POST /api/v1/licenses/revoke`
- `POST /api/v1/licenses/heartbeat`
- `GET /api/v1/updates/latest`
- `GET /api/v1/updates/{id}/download`

Legacy compatibility routes also exist for:

- `POST /licenses/activate`
- `POST /licenses/status`
- `POST /licenses/revoke`

### Request payload compatibility

The API validators normalize both naming styles, so the client can send either:

- `license_key` or `licenseKey`
- `device_name`, `deviceName`, `pc_name`, `pcName`, or `machineName`
- `app_version` or `appVersion`
- `machine_id`, `machineId`, `device_id`, or `deviceId`

`machine_id` is required for license activation, status checks, heartbeats, and revocation.

### Activate license

Endpoint:

```http
POST /api/v1/licenses/activate
```

Expected payload:

```json
{
  "license_key": "123456789012",
  "device_name": "OFFICE-PC-01",
  "machine_id": "machine-guid-123",
  "app_version": "1.0.0"
}
```

How activation works:

1. The backend finds the license by its 12-digit code.
2. If the license is expired, activation is rejected.
3. If the license is already assigned to another machine, activation is rejected with HTTP `409`.
4. If the license is unused, the server binds it to the current PC and machine ID.
5. The server updates `last_seen_at`, IP address, and app version.
6. A success JSON payload is returned with the current license data.

Machine binding behavior:

- The first successful activation stores the supplied `machine_id`.
- After that, only the same `machine_id` can activate, check status, send heartbeat, or revoke the license.
- A matching `device_name` alone is not enough to move or reuse a license.

### Check status

Endpoint:

```http
POST /api/v1/licenses/status
```

How it works:

- The request must come from the same machine bound to the license.
- If the device matches and the license is not expired, the server refreshes heartbeat metadata.
- The response includes the calculated status.

### Heartbeat

Endpoint:

```http
POST /api/v1/licenses/heartbeat
```

How it works:

- This is effectively a periodic "still alive" call from TimerApp.
- It requires the same bound machine validation as status checks.
- It updates:
  - `last_seen_at`
  - `last_seen_ip`
  - `app_version`

This endpoint is what drives the dashboard's Active vs Inactive view.

### Revoke license

Endpoint:

```http
POST /api/v1/licenses/revoke
```

How it works:

- The current machine must match the bound device.
- The backend clears:
  - `device_name`
  - `machine_id`
  - `activated_at`
  - `last_seen_at`
  - `last_seen_ip`
  - `app_version`
- After that, the license becomes `Available` again.

### Check for the latest app update

Endpoint:

```http
GET /api/v1/updates/latest?current_version=1.0.0
```

How it works:

1. The backend finds the latest active published update.
2. If there is no such record, it returns:

```json
{
  "has_update": false,
  "update": null
}
```

3. If an update exists, the backend compares `current_version` with the stored version using `version_compare()`.
4. The response always includes the latest live update record, and `has_update` tells the client whether it is newer than the current version.

Returned update fields include:

- `id`
- `title`
- `version`
- `description`
- `fileName`
- `fileSize`
- `publishedAt`
- `downloadUrl`

### Download an app update

Endpoint:

```http
GET /api/v1/updates/{id}/download
```

How it works:

- The update must be active and published.
- Laravel streams the file from the `public` disk with the original filename.

## Database model

### `users`

Purpose:

- Stores login accounts for the portal

Important fields:

- `name`
- `email`
- `password`
- `is_admin`

### `licenses`

Purpose:

- Stores license lifecycle and machine heartbeat data

Important fields:

- `code`
- `expires_at`
- `device_name`
- `machine_id`
- `activated_at`
- `last_seen_at`
- `last_seen_ip`
- `app_version`
- `created_by`

### `news_posts`

Purpose:

- Stores announcements that appear on the public home page

Important fields:

- `title`
- `body`
- `is_pinned`
- `published_at`
- `created_by`

### `app_updates`

Purpose:

- Stores metadata for desktop app packages available to TimerApp clients

Important fields:

- `version`
- `title`
- `description`
- `file_path`
- `file_name`
- `file_size`
- `is_active`
- `published_at`
- `uploaded_by`

## Frontend structure

Main frontend entry points:

- `resources/js/app.js`: boots Inertia + Svelte
- `resources/views/app.blade.php`: HTML shell
- `resources/js/layouts/PublicLayout.svelte`: public wrapper
- `resources/js/layouts/AdminLayout.svelte`: admin wrapper

Pages:

- `resources/js/pages/HomePage.svelte`
- `resources/js/pages/auth/LoginPage.svelte`
- `resources/js/pages/admin/DashboardPage.svelte`

Reusable components:

- `resources/js/components/StatCard.svelte`
- `resources/js/components/TablePill.svelte`

What the frontend receives from Laravel:

- Authenticated user info
- CSRF token
- Flash messages
- Page-specific data from the relevant controller

## File storage

Uploaded app packages are stored on Laravel's `public` disk.

In practice this means:

- Physical storage path: `storage/app/public/updates/...`
- Public symlink path: `public/storage/...`

That is why this command is required during setup:

```bash
php artisan storage:link
```

## Local setup

### Requirements

- PHP 8.2 or newer
- Composer
- Node.js
- MySQL

### 1. Install dependencies

```bash
composer install
npm install
```

### 2. Configure environment

Copy `.env.example` to `.env` if needed, then set your app and database values.

Typical values for this project:

```env
APP_NAME=TimerAdmin
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=timer_admin
DB_USERNAME=timeradmin
DB_PASSWORD=change_me_strong_password

TIMER_ACTIVE_WINDOW_MINUTES=10
```

Optional helper:

- `database/sql/timer_admin_setup.sql` creates the MySQL database and `timeradmin` user.

### 3. Generate app key if needed

If your `.env` does not already contain `APP_KEY`, run:

```bash
php artisan key:generate
```

### 4. Create schema and seed default data

```bash
php artisan migrate --seed
```

### 5. Create the storage symlink

```bash
php artisan storage:link
```

### 6. Start the app

Terminal 1:

```bash
php artisan serve
```

Terminal 2:

```bash
npm run dev
```

Open:

```text
http://127.0.0.1:8000
```

## Seeded default data

The database seeder creates:

- Admin user:
  - Email: `admin@timerapp.local`
  - Password: `changeme123`
- A pinned welcome news post

Change the default password immediately outside local development.

## Important behavior notes

- This app currently creates records, but it does not yet include edit or delete flows for licenses, news posts, or app updates.
- The dashboard is a full overview page; it does not paginate or lazy-load large datasets.
- Status is computed dynamically in PHP, not stored as a dedicated database column.
- API responses return both snake_case and camelCase license fields in `toApiArray()` to make client integration easier.
- Update downloads are protected by publication state and active state, not by login.
- A revoked license becomes reusable.

## Project map

Backend:

- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/Admin/...`
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Controllers/Api/TimerAppController.php`
- `app/Models/License.php`
- `app/Models/NewsPost.php`
- `app/Models/AppUpdate.php`

Frontend:

- `resources/js/app.js`
- `resources/js/layouts/...`
- `resources/js/pages/...`
- `resources/css/app.css`

Database:

- `database/migrations/...`
- `database/seeders/DatabaseSeeder.php`
- `database/sql/timer_admin_setup.sql`

## Testing status

The `tests/Feature` and `tests/Unit` directories exist, but there are currently no test files in the repository. If you add automated coverage later, the most valuable first targets would be:

- license activation rules
- machine binding behavior
- status transitions
- update publishing behavior
- admin access restrictions
