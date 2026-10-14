# Laravel + React

Laravel 12 with React 19, Inertia 2, TypeScript, Tailwind CSS 4, and Vite. Based on the official Laravel React starter kit, with registration, login, password reset, profile settings, and an authenticated dashboard.

## Local development

Requirements: PHP 8.2+, Composer, Node.js 22, and npm. PHP must have PDO SQLite enabled.

For a fresh clone, complete the setup steps below first. Once dependencies, the application key, and the SQLite database are ready, start the app from this folder:

```powershell
composer run dev
```

Open http://127.0.0.1:8001. This starts Laravel on port 8001, the queue worker, and Vite on port 5174. Press Ctrl+C to stop them. Register an account to access the dashboard.

Port 8000 remains available for other local projects. Use the full address above for this project. The ports are fixed so a conflict produces an error instead of silently moving the app to another address. This project also uses its own session cookie, `ppmp_test_session`.

The development command supports Windows/XAMPP; it excludes Laravel Pail because Pail requires the Unix PCNTL extension. Application logs are in `storage/logs/laravel.log`.

## Files to edit

- `routes/web.php`: application routes
- `app/Http/Controllers`: backend controllers
- `app/Models`: database models
- `resources/js/pages`: React pages
- `resources/js/components`: shared React components
- `resources/css/app.css`: styles
- `database/migrations`: database schema
- `.env`: local configuration (do not commit this file)

SQLite stores local data in `database/database.sqlite`. No MySQL server is required. Email uses the log driver, so password reset messages appear in the Laravel log until an email provider is configured.

## Checks

```powershell
npm.cmd run build
npm.cmd run types
composer test
```

## Set up another checkout

```powershell
git clone https://github.com/arnaut560000/shared-ppmp.git
cd shared-ppmp
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite -ErrorAction SilentlyContinue
php artisan migrate
npm.cmd ci
npm.cmd run build
composer run dev
```

Laravel 12 was selected for compatibility with the installed PHP 8.2 runtime. Laravel 13 requires PHP 8.3 or newer.
