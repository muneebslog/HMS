# Hospital Management System (HMS)

A web-based Hospital Management System built with Laravel, Livewire, and Flux UI. It helps reception and management staff manage patients, services, doctors, walk-in visits, lab invoices, procedures, queues, shifts, expenses, and payouts from a single interface.

## Tech Stack

- **Backend:** PHP 8.4, Laravel 13, Laravel Fortify
- **Frontend:** Livewire 4, Flux UI 2, Tailwind CSS 4, Vite
- **Database:** SQLite by default (MySQL/PostgreSQL configurable)
- **Testing:** Pest 4, Laravel Pint, PHPStan (Larastan)
- **Authentication:** Laravel Fortify with email verification, two-factor auth, and passkey support

## Features

- **Role-based access control** with three built-in roles: Admin, Receptionist, and Management
- **Patient registration** and walk-in visit handling
- **Service pricing** and invoice generation for walk-in and lab services
- **Lab entry** and **lab invoices** with test management
- **Procedures** with payment tracking
- **Queue management** with tokens for patients
- **Shift management** with opening/closing balances, sales, expenses, and expected cash reconciliation
- **Doctor payouts** and **daily payouts** linked to shifts
- **Expense tracking** per shift
- **User management** page for admins to assign roles
- **Printable invoices**

## User Roles

| Role | Access |
|------|--------|
| **Admin** | Full access: Management CRUD, user roles, all reception pages, invoices, payouts, shifts, settings |
| **Receptionist** | Walk-in, reservations, lab entry, procedures, queue, daily payout, shift |
| **Management** | Invoices, doctor payout, shift, settings |

New registrations default to the `receptionist` role. Only an admin can promote users to other roles.

## Installation

### Requirements

- PHP 8.3+
- Composer
- Node.js 20+
- SQLite extension enabled (or configure MySQL/PostgreSQL in `.env`)

### Quick Start

```bash
# Install PHP and JavaScript dependencies
composer install
npm install

# Create environment file and generate app key
cp .env.example .env
php artisan key:generate

# Create SQLite database file and run migrations
touch database/database.sqlite
php artisan migrate

# Build frontend assets
npm run build
```

### Create the First Admin

Use the Artisan command to create or promote an admin user:

```bash
php artisan user:admin "Admin User" admin@example.com password
```

Or seed the database with sample data and a default admin:

```bash
php artisan db:seed
```

### Default Seeded Credentials

After running `php artisan db:seed`:

| Email | Password | Role |
|-------|----------|------|
| `admin@example.com` | `password` | Admin |
| `test@example.com` | `password` | Receptionist |

> Change these credentials before deploying to production.

## Development

Run the development servers:

```bash
composer run dev
```

This starts the Laravel server, queue worker, and Vite dev server concurrently.

### Useful Commands

```bash
# Run tests
php artisan test --compact

# Run linter
composer run lint

# Check types
composer run types:check

# Run the full CI suite
composer run test
```

## Testing

The project uses Pest for testing. Run the full test suite:

```bash
php artisan test --compact
```

Key test files:

- `tests/Feature/RoleMiddlewareTest.php` — role-based route access
- `tests/Feature/AdminUsersPageTest.php` — admin user management
- `tests/Feature/MakeAdminCommandTest.php` — admin creation command
- `tests/Feature/ShiftTest.php` — shift and expected cash reconciliation
- `tests/Feature/ReceptionInvoiceListingTest.php` — current-shift invoices

## Project Structure Highlights

```
app/
  Console/Commands/MakeAdminUser.php      # user:admin command
  Enums/UserRole.php                      # Admin, Receptionist, Management
  Http/Middleware/EnsureUserRole.php      # role middleware
  Http/Middleware/EnsureOpenShift.php     # requires an open shift
  Models/                                 # User, Patient, Shift, Invoice, etc.
  Services/                               # QueueService, ReservationService

resources/views/pages/                   # Livewire page components
  admin/users.blade.php
  management/crud.blade.php
  payout/daily.blade.php
  payout/doctor.blade.php
  reception/*.blade.php

routes/web.php                           # role-grouped application routes
tests/Feature/                           # Pest feature tests
```

## License

This project is open-sourced software licensed under the MIT license.
