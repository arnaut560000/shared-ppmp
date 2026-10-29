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

## Planning backend foundation

The planning migrations, models, and services are now included. They support office budget ceilings, AIP/PPMP approval rules, quarterly quantities, saved catalogue prices, audit events, and approved-PPMP report aggregation. Dashboard, administration, office budgets, and AIP draft creation/editing now have connected screens. AIP submission/review, catalogue management, PPMP screens, APP consolidation, and procurement/inventory screens remain future work.

PHP requires the BCMath and PDO SQLite extensions. After pulling this change into an existing local setup:

```powershell
composer install
php artisan migrate
composer test
```

Use the fresh-checkout instructions above if `.env` and the local database do not exist yet. Keep your existing application key and database when updating an existing installation. Do not run `migrate:fresh` to apply this update.

The quarterly migration preserves legacy monthly quantities, totals, and document state. Rolling it back is blocked once quarterly-only records exist, because their monthly quantities cannot be reconstructed.

The focused tests cover service permissions, office budget reservations, stale versions, approval locking, price snapshots, report filters, and legacy quarterly migration. They use an in-memory SQLite database.


## AIP draft workflow

Open `/aips` after signing in. Administrators and office users with an assigned office can create AIPs; other recognized roles can view the list. Office accounts see only their own office documents.

1. Select the fiscal year and office, then enter a unique AIP reference, title, fund source, dates, and proposed PS/MOOE/FE/CO amounts in pesos.
2. Save the draft. The server calculates the amount in centavos and records an audit event. Drafts do not reserve the office budget.
3. Use Edit to update a draft or returned AIP. Office and fiscal year stay fixed. Outdated edits are rejected; submitted and approved plans are locked.

After pulling this update into an already configured checkout, run `npm ci`, `npm run build`, and `php artisan migrate`. Start with `composer run dev` and open http://127.0.0.1:8001/aips. Each checkout has its own local database; GitHub does not synchronize users, offices, or budgets.

Run `php artisan test --filter=AipTest` for HTTP checks covering creation, editing, office privacy, reviewer permissions, validation, and document locking. Submission and approval buttons are not part of this update.

## SQLite process

[.env.example (line 26)]
DB_CONNECTION=sqlite
'''This tells Laravel to use SQLite.'''

-- SQLite connection configuration --
[config/database.php (line 19)]
'default' => env('DB_CONNECTION', 'sqlite'),
'''Laravel DB_CONNECTION'''

## Laravel migrations create the tables

---SQLite sample syntax---
Schema::create('offices', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->timestamps();
});

---SQLite into SQL---
CREATE TABLE offices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR NOT NULL UNIQUE,
    name VARCHAR NOT NULL,
    created_at DATETIME,
    updated_at DATETIME
);



## Table definitions
The main system tables begin at these lines:
- offices: [planning migration:11 (line 11)]
CREATE TABLE offices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    CONSTRAINT offices_code_unique UNIQUE (code)
);

- User role and office assignment: [planning migration:17 (line 17)]
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at DATETIME NULL,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    office_id INTEGER NULL,
    role VARCHAR(255) NOT NULL DEFAULT 'office',

    FOREIGN KEY (office_id)
        REFERENCES offices(id)
        ON DELETE RESTRICT
);

- budgets: [planning migration:21 (line 21)]
CREATE TABLE budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    office_id INTEGER NOT NULL,
    fiscal_year INTEGER NOT NULL,
    ceiling_cents INTEGER NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    FOREIGN KEY (office_id)
        REFERENCES offices(id)
        ON DELETE RESTRICT,

    CONSTRAINT budgets_office_year_unique
        UNIQUE (office_id, fiscal_year)
);

- catalogue_items: [planning migration:29 (line 29)]
CREATE TABLE catalogue_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    specification TEXT NULL,
    unit VARCHAR(40) NOT NULL,
    category VARCHAR(255) NOT NULL,
    expense_class VARCHAR(10) NOT NULL,
    price_cents INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    CONSTRAINT catalogue_items_code_unique UNIQUE (code),

    CONSTRAINT catalogue_items_active_boolean
        CHECK (active IN (0, 1))
);

- annual_plans: [planning migration:41 (line 41)]
CREATE TABLE annual_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiscal_year INTEGER NOT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(255) NOT NULL DEFAULT 'submitted',
    created_by INTEGER NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    FOREIGN KEY (created_by)
        REFERENCES users(id)
);

- plans: [planning migration:49 (line 49)]
CREATE TABLE plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    type VARCHAR(10) NOT NULL,
    office_id INTEGER NOT NULL,
    fiscal_year INTEGER NOT NULL,
    reference VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    fund_source VARCHAR(255) NOT NULL,
    expected_output TEXT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,

    status VARCHAR(255) NOT NULL DEFAULT 'draft',
    version INTEGER NOT NULL DEFAULT 1,

    parent_id INTEGER NULL,
    annual_plan_id INTEGER NULL,
    created_by INTEGER NOT NULL,

    ps_cents INTEGER NOT NULL DEFAULT 0,
    mooe_cents INTEGER NOT NULL DEFAULT 0,
    fe_cents INTEGER NOT NULL DEFAULT 0,
    co_cents INTEGER NOT NULL DEFAULT 0,
    total_cents INTEGER NOT NULL DEFAULT 0,

    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    FOREIGN KEY (office_id)
        REFERENCES offices(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (parent_id)
        REFERENCES plans(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (annual_plan_id)
        REFERENCES annual_plans(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (created_by)
        REFERENCES users(id),

    CONSTRAINT plans_unique_reference
        UNIQUE (office_id, fiscal_year, type, reference)
);

- plan_items: [planning migration:75 (line 75)]
CREATE TABLE plan_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plan_id INTEGER NOT NULL,
    catalogue_item_id INTEGER NOT NULL,

    code VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    specification TEXT NULL,
    unit VARCHAR(255) NOT NULL,
    expense_class VARCHAR(255) NOT NULL,
    price_cents INTEGER NOT NULL,

    monthly_quantities TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    total_cents INTEGER NOT NULL,

    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    FOREIGN KEY (plan_id)
        REFERENCES plans(id)
        ON DELETE CASCADE,

    FOREIGN KEY (catalogue_item_id)
        REFERENCES catalogue_items(id)
        ON DELETE RESTRICT,

    CONSTRAINT plan_items_plan_catalogue_unique
        UNIQUE (plan_id, catalogue_item_id)
);

- audit_events: [planning migration:91 (line 91)]
CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    office_id INTEGER NULL,

    subject_type VARCHAR(255) NOT NULL,
    subject_id INTEGER NOT NULL,
    action VARCHAR(255) NOT NULL,
    comment TEXT NULL,
    metadata TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    FOREIGN KEY (office_id)
        REFERENCES offices(id)
        ON DELETE RESTRICT
);