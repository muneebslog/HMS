# HMS Codebase Audit Report

> Audit date: 2026-06-24
> Application: Laravel 13 Hospital Management System
> Tests status: **206 passed, 751 assertions**

---

## Executive Summary

This audit reviewed the Laravel HMS codebase for security vulnerabilities, logical/business logic bugs, and Laravel best-practice gaps. While the test suite is comprehensive and many Laravel conventions are followed, several **critical** and **high** severity issues were identified that should be addressed before production use.

### Issue Counts

| Severity | Count |
|----------|-------|
| Critical | 3 |
| High | 10 |
| Medium | 13 |
| Low | 7 |

---

## 🔴 Critical Issues

### 1. Mass-Assignment of `email_verified_at`

- **File:** `app/Models/User.php:34`
- **Issue:** The `email_verified_at` timestamp is included in the `$fillable` array. Because Fortify's email verification feature is enabled, a malicious user could mass-assign this field and bypass email verification entirely.
- **Recommendation:** Remove `email_verified_at` from `$fillable`. It should only ever be set internally by Laravel's verification flow.

### 2. Client-Trusted Invoice Pricing (Walk-in)

- **File:** `resources/views/pages/reception/⚡walkin.blade.php:204-265`
- **Issue:** The `saveInvoice()` action persists `price`, `service_name`, and `doctor_name` directly from the client-side `items` array. A user can alter these values in the Livewire request payload, leading to incorrect invoices and revenue manipulation.
- **Recommendation:** During save, re-fetch the authoritative price from `ServicePrice` by `service_id` and `doctor_id`. Only allow explicit, authorized price overrides with an audit trail.

### 3. Client-Trusted Lab Test Pricing

- **File:** `resources/views/pages/reception/⚡lab-entry.blade.php:138-199`
- **Issue:** Same pattern as above: `test_price`, `test_name`, `test_code`, and other item fields are persisted from the client-controlled `items` array.
- **Recommendation:** Re-fetch the lab test record by ID during save and use its stored price and name.

---

## 🟠 High Severity Issues

### 4. Print Job State Machine Not Enforced

- **File:** `app/Http/Controllers/Api/PrintJobController.php:34-59`
- **Issue:** The `printed()` and `failed()` endpoints accept any `PrintJob` regardless of its current state. An already-printed or already-failed job can be transitioned again, corrupting metrics and timestamps.
- **Recommendation:** Guard both actions so they only operate on jobs in the `pending` state:

```php
abort_if($job->status !== PrintJobStatus::Pending, 422, 'Job is not pending.');
```

### 5. 2FA / Passkey Destructive Actions Lack Password Confirmation

- **Files:**
  - `resources/views/pages/settings/⚡security.blade.php:161-166` (2FA disable)
  - `resources/views/pages/settings/⚡security.blade.php:125-137` (passkey delete)
- **Issue:** Although `config/fortify.php` sets `confirmPassword => true` for both features, the custom Livewire actions are callable without re-authentication. A session hijacker could disable 2FA or remove passkeys.
- **Recommendation:** Require the `password.confirm` middleware on the security settings route, or prompt for the current password before these actions.

### 6. Email Verification Feature Misconfigured

- **Files:** `config/fortify.php:166` and `app/Models/User.php:5`
- **Issue:** Fortify's `Features::emailVerification()` is enabled, but the `User` model does not implement `Illuminate\Contracts\Auth\MustVerifyEmail`. As a result, the `verified` middleware never redirects unverified users.
- **Recommendation:** Either uncomment `implements MustVerifyEmail` on the `User` model or remove the email verification feature from `config/fortify.php`.

### 7. Print Agent API Uses Static Bearer Token

- **Files:** `routes/api.php:6-10`, `app/Http/Middleware/EnsurePrintAgentToken.php`
- **Issue:** The print agent API is protected by a single static token (`PRINT_AGENT_TOKEN`) stored in config. There is no hashing, expiry, rotation, or per-agent identity. If leaked, an attacker can read and mutate print jobs.
- **Recommendation:** Migrate to Laravel Sanctum with per-agent tokens. Hash any shared secret and add rate limiting to the API group.

### 8. Missing Authorization Policies (IDOR Risk)

- **File:** Project-wide (`app/Policies/` is empty)
- **Issue:** Authorization exists only at the route/middleware level (role checks). Once a user passes the role gate, they can operate on any record ID they can guess. Examples:
  - Any Management user can print any invoice by ID.
  - Any Receptionist can view all procedures, all queues, and all shifts.
  - Any Admin can delete doctors/services even when related records exist.
- **Recommendation:** Create and register Laravel Policies for all domain models: `Invoice`, `LabInvoice`, `Procedure`, `Shift`, `DoctorPayout`, `RoleRequest`, `ServiceQueue`, etc.

### 9. Open Shift Middleware Lets Unauthenticated Requests Through

- **File:** `app/Http/Middleware/EnsureOpenShift.php:17-23`
- **Issue:** The middleware returns `$next($request)` when `$user === null`. Routes using this middleware are also protected by `auth`, but the behavior is fragile and could become a vulnerability if route grouping changes.
- **Recommendation:** Return `abort(401)` or `redirect()->route('login')` for unauthenticated users.

### 10. Profile Settings Do Not Require Verified Email

- **File:** `routes/settings.php:5-9`
- **Issue:** Profile settings use `auth` and `role.assigned` middleware but not `verified`. If email verification is enabled, unverified users can still update their profile.
- **Recommendation:** Add `verified` middleware to the profile route group when `MustVerifyEmail` is enabled.

### 11. Invoice Print Route Exposes Any Invoice to Management

- **File:** `routes/web.php:22`
- **Issue:** The `invoices.print` route uses implicit model binding but does not scope the invoice to the current user or shift. Any Management user can view/print any invoice in the system.
- **Recommendation:** Add a policy check or scope the query to invoices belonging to the user's current/open shift.

### 12. Raw SVG Output in 2FA Setup

- **File:** `resources/views/pages/settings/⚡two-factor-setup-modal.blade.php:239`
- **Issue:** The QR code is rendered with `{!! $qrCodeSvg !!}`, which bypasses Blade escaping.
- **Recommendation:** This is acceptable only if Fortify generates the SVG server-side and no user input influences it. Add a comment/code audit to confirm this remains true after upgrades.

### 13. RoleRequest Mass-Assignment of Status / Processor Fields

- **File:** `app/Models/RoleRequest.php:37-45`
- **Issue:** `processed_by`, `processed_at`, and `status` are mass-assignable. They are currently updated through explicit arrays, but this increases the risk of accidental or malicious status changes.
- **Recommendation:** Move these fields out of `$fillable` and update them through dedicated model methods such as `markApproved(User $admin)` and `markRejected(User $admin)`.

---

## 🟡 Medium Severity Issues

### 14. Race Condition When Opening / Closing Shifts

- **Files:**
  - `resources/views/pages/reception/⚡shift.blade.php:44-68` (open)
  - `resources/views/pages/reception/⚡shift.blade.php:73-96` (close)
- **Issue:** `Shift::currentForUser()` is queried and then acted upon in separate steps. Concurrent requests can create multiple open shifts for the same user or close a shift twice.
- **Recommendation:** Use `lockForUpdate()` when reading the shift, or enforce a unique partial index on `(user_id, status)` where `status = 'open'`.

### 15. Race Condition in Daily / Range Doctor Payouts

- **Files:**
  - `resources/views/pages/payout/⚡daily.blade.php:139-166`
  - `resources/views/pages/payout/⚡doctor.blade.php:194-225`
- **Issue:** `markPaid()` checks whether a payout already exists and then inserts a record. Two simultaneous requests can both pass the check and create duplicate payouts.
- **Recommendation:** Wrap the check and insert in a database transaction and use `lockForUpdate()` on the existing payout row, or add a unique database index.

### 16. Race Condition in Procedure Payments

- **File:** `resources/views/pages/reception/⚡procedures.blade.php:284-316`
- **Issue:** `savePayment()` reads the remaining balance and then inserts a payment without locking. Concurrent payments can exceed the procedure's full amount.
- **Recommendation:** Lock the procedure row before checking the balance and creating the payment.

### 17. Duplicate Queue Tokens Possible

- **File:** `app/Services/QueueService.php:20-48`
- **Issue:** `generateToken()` does not verify that the given `InvoiceItem` already has a token. Calling it twice for the same item will create duplicate tokens.
- **Recommendation:** Add a guard:

```php
abort_if($invoiceItem->queueToken !== null, 422, 'Token already generated.');
```

### 18. Hardcoded "consultation" Service Name

- **Files:**
  - `app/Services/ReservationService.php:75`
  - `resources/views/pages/reception/⚡reservation.blade.php:192`
  - `resources/views/pages/management/⚡crud.blade.php:87-99`
- **Issue:** The service name `'consultation'` is hardcoded in multiple locations using a case-insensitive raw SQL lookup. Renaming the service in management will break reservations and arrivals.
- **Recommendation:** Add a config value (e.g., `services.conservation_id`) or a dedicated scope/method such as `Service::consultation()`.

### 19. Role Request Approval Race Condition

- **File:** `resources/views/pages/admin/⚡users.blade.php:155-185`
- **Issue:** `approveRequest()` fetches the request via `currentRequest()` without re-checking that it is still pending. A request approved in another tab/request can be approved twice.
- **Recommendation:** Re-check `$request->isPending()` inside the transaction before updating.

### 20. Admin Self-Demotion Logic Too Restrictive

- **File:** `resources/views/pages/admin/⚡users.blade.php:107-122`
- **Issue:** `wouldLockOutAdmin()` always returns `true` when an admin attempts to demote themselves, even if other admins exist.
- **Recommendation:** Only block self-demotion when `User::where('role', UserRole::Admin)->count() <= 1`.

### 21. Procedure Edit Lacks Ownership Check

- **File:** `resources/views/pages/reception/⚡procedures.blade.php:97-115`
- **Issue:** `edit()` loads any `Procedure` by ID. A Receptionist can load procedures created by other users or in other shifts.
- **Recommendation:** Add a policy check or scope the query to the current user's shifts.

### 22. Queue Detail Not Scoped to Current Shift

- **File:** `resources/views/pages/reception/⚡queue.blade.php:77-86`
- **Issue:** `viewedQueue()` returns any `ServiceQueue` by ID, allowing users to view queues from other shifts.
- **Recommendation:** Scope the query to queues belonging to the current user's open shift or today's daily queues.

### 23. Print Job Retry Lacks State Check

- **File:** `resources/views/pages/reception/⚡print-jobs.blade.php:37-50`
- **Issue:** The `retry()` action resets any job to `pending` without verifying it is currently `failed`.
- **Recommendation:** Add `abort_if($job->status !== PrintJobStatus::Failed, 422)`.

---

## 🟢 Low Severity / Best Practice Issues

### 24. No Form Request Classes

- **File:** Project-wide
- **Issue:** Validation is performed inline inside Livewire components and controllers. This bloats action methods and makes validation harder to reuse.
- **Recommendation:** For HTTP routes (e.g., the print agent API), create Form Request classes. For Livewire, prefer the component-level `rules()` method consistently.

### 25. Inline Validation in Print Agent Controller

- **File:** `app/Http/Controllers/Api/PrintJobController.php:49`
- **Issue:** Uses `Validator::make($request->all(), ...)->validate()` instead of a dedicated Form Request.
- **Recommendation:** Create `MarkPrintJobFailedRequest` and type-hint it in the controller method.

### 26. Inline Validation in Doctor Payout Component

- **File:** `resources/views/pages/payout/⚡doctor.blade.php:172-189`
- **Issue:** Uses `Validator::make()` manually instead of the component's `rules()` method.
- **Recommendation:** Move the date range rules to `rules()` or use `#[Validate]` attributes.

### 27. Heavy Use of `whereRaw('LOWER(name) = ?')`

- **Files:** `app/Services/ReservationService.php:75`, `resources/views/pages/reception/⚡reservation.blade.php:192`, `resources/views/pages/management/⚡crud.blade.php:92`
- **Issue:** Case-insensitive lookups are implemented with raw SQL, which is harder to maintain and less portable.
- **Recommendation:** Use a case-insensitive collation or a model scope.

### 28. Token Number Allocation Scales Poorly

- **File:** `app/Services/QueueService.php:91-104`
- **Issue:** `peekNextTokenNumber()` loads every token number for a queue into memory to find the next gap. This becomes slow as queues grow.
- **Recommendation:** Use a counter column (`last_token_number`) consistently, or use a database window function / aggregate query.

### 29. Print Jobs List Not Paginated

- **File:** `resources/views/pages/reception/⚡print-jobs.blade.php:21-32`
- **Issue:** Jobs are loaded with only a `limit($this->perPage)` and no offset. The "load more" pattern is not implemented, so older jobs are inaccessible beyond the limit.
- **Recommendation:** Implement Laravel pagination or cursor-based pagination.

### 30. Doctor Payout Migrations Split Across Multiple Files

- **Files:**
  - `database/migrations/2026_06_23_192257_create_doctor_payouts_table.php`
  - `database/migrations/2026_06_23_201417_add_shift_id_to_doctor_payouts_table.php`
  - `database/migrations/2026_06_24_000000_add_date_range_to_doctor_payouts_table.php`
- **Issue:** The payout table schema is fragmented across migrations. This is acceptable if the migrations have already run, but it complicates reversibility and fresh installs.
- **Recommendation:** For new projects, consolidate schema in the original migration. Document why later migrations are needed.

### 31. Management Delete Actions Lack Referential Integrity Checks

- **File:** `resources/views/pages/management/⚡crud.blade.php:338-348`
- **Issue:** `delete()` removes doctors, services, service prices, and lab tests without checking for dependent records. This will throw foreign-key constraint errors or leave orphan data.
- **Recommendation:** Use `onDelete('restrict')` and show a clear error, or implement soft deletes / cascade where appropriate.

### 32. User Factory Defaults to Verified

- **File:** `database/factories/UserFactory.php:31`
- **Issue:** The factory defaults `email_verified_at` to `now()`, which is overridden by the `unverified()` state. This default can hide verification-related bugs in tests.
- **Recommendation:** Default `email_verified_at` to `null` and use an explicit `verified()` state if needed.

### 33. Inconsistent Refresh Database Configuration

- **File:** `tests/Pest.php:18`
- **Issue:** `RefreshDatabase` is commented out in `Pest.php`, so each test file must declare `uses(RefreshDatabase::class)` individually. This is easy to forget.
- **Recommendation:** Uncomment the global `RefreshDatabase` trait and remove per-file duplication, or adopt `LazilyRefreshDatabase` for speed.

### 34. Missing Architecture / Browser Tests

- **File:** `tests/`
- **Issue:** There are no Pest architecture tests, browser tests, or smoke tests.
- **Recommendation:** Add architecture tests to enforce naming conventions and a small browser-test suite for critical user flows (walk-in invoice, payout, role approval).

---

## ✅ Positive Observations

- All models use explicit `$fillable` arrays (no dangerous `$guarded = []`).
- CSRF tokens are included on every POST form.
- Passwords use Laravel's `hashed` cast.
- Fortify rate limiting is configured for login, 2FA, and passkeys.
- Custom `LoginResponse` and `RegisterResponse` correctly redirect pending users.
- Database transactions wrap multi-step writes.
- Eager loading is used in most computed properties.
- The feature test suite is large and currently passes.

---

## Recommended Priority Order

1. **Fix Critical issues 1-3** (mass assignment and client-trusted pricing).
2. **Implement policies and scope resource queries** to resolve IDOR risks (issues 8, 11, 21, 22).
3. **Add password confirmation to destructive security actions** (issue 5).
4. **Fix race conditions** in shifts, payouts, and payments (issues 14-16).
5. **Resolve email verification misconfiguration** (issue 6).
6. **Harden the print agent API** (issue 7) and enforce print job state transitions (issue 4).
7. **Address best-practice and maintainability issues** (Form Requests, pagination, hardcoded strings, missing tests).
