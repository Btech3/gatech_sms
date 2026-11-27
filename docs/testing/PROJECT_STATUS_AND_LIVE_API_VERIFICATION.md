# Project status and live API verification

**Checked:** 2026-11-27

**Branch and revision:** `feature/auth-cookie-v1`, `6ea5d04`

**Local test origin:** `http://127.0.0.1:8010`

## Executive summary

The repository contains a Laravel API backend for authentication, role and permission administration, academy setup, user invitations, audit logs, and an admissions workflow foundation. It is not a complete School Management Portal: there is no student, applicant, or administrator-facing web application in this repository.

The PHPUnit suite passes on its configured SQLite test database: **93 tests, 535 assertions**. This demonstrates automated behavior under that test setup, not successful execution against the configured MySQL database.

The configured MySQL connection is reachable, but **five application migrations are pending**: role/permission and audit tables, academy setup, user-campus assignments, invitations, and admissions. The corresponding business tables are absent from MySQL. No schema migration or school business-record write was performed for this review; the CSRF request used ordinary session handling.

Swagger was opened and requests were executed against the local server. The confirmed live results are limited to health, documentation, CSRF/session bootstrap, authentication denial, and validation responses. A successful login or a successful authenticated administration/admissions workflow was **not** verified.

## Application structure

| Area | Contents |
|---|---|
| `app/` | API controllers, Form Requests, JSON resources, models, middleware, and authentication, authorization, audit, settings, and admissions services |
| `routes/api.php` | Versioned `/api/v1` authentication and administration routes |
| `routes/web.php` and `resources/views/` | Welcome page and Swagger UI; no user-facing portal screens |
| `database/migrations/` | 12 migration files covering authentication plus the later administration and admissions schema |
| `database/seeders/` | Role and permission catalogue and base seeding |
| `database/factories/` | Test data factories |
| `tests/` | PHPUnit feature coverage, including authentication and administration/admissions behavior |
| `docs/api/` and `docs/testing/` | OpenAPI contract, endpoint inventory, and manual testing guides |

Laravel reports **75 registered routes**: 68 versioned API operations and seven web, documentation, health, storage, or development-tool routes. The Swagger UI displayed 68 API operations, and a route-to-OpenAPI method comparison found no missing versioned operations.

## What is implemented in the repository

“Implemented” below means code and route definitions exist. It does not mean the feature has been exercised against the current MySQL schema.

| Area | Present in code | Current limitation |
|---|---|---|
| AUTH-001–004 | Session-cookie login/logout, registration, verification, password recovery, profile/password changes, and idle timeout | No successful live login was possible with the current local accounts. MFA and the five-incorrect-code MFA lock are not implemented. SMTP delivery was not tested. |
| ADN-002/003 | Role catalogue, role CRUD/clone, permission matrix, permission middleware, and controlled CLI bootstrap of the first System Administrator | RBAC tables and seeding migrations are pending in MySQL. |
| ADN-001 | User listing/invitations, invitation acceptance, deactivation, soft deletion, and reset-email request routes | User-management and invitation migrations are pending in MySQL. |
| ADN-004–008 | Academy profile, campuses/rooms, academic sessions/intakes/holidays, departments, and supported system settings | These administration migrations are pending in MySQL. No front-end screens consume these APIs. |
| ADN-009 | Audit search/export and rejected mutation routes; audit events are exercised by feature tests | MySQL audit table is not present yet. Retention and deployment operations still need end-to-end verification. |
| ADM-001–010 | API foundation for application creation, review, accept/reject/waitlist, admission into student/enrollment records, letters, withdrawal, waitlist promotion, and optional public submission | Admissions migrations are pending in MySQL. A successful admissions workflow has only been verified in SQLite tests, not in the live application. Course-management endpoints are not registered, so the course/intake setup needed for testing must be prepared separately. |
| XCT-001 | Backend permission middleware and permission catalogue provide authorization building blocks | Permission-driven navigation UI/API is not present. |
| XCT-002 | None | Responsive portal UI is not present. |
| XCT-003 | Several administrative collection endpoints use pagination | Consistent list behavior across a portal UI is not implemented or verified. |
| XCT-004 | None | No legacy import pipeline or supplied legacy dataset was found in the repository. |
| XCT-005 | Authentication, backend authorization, validation, throttling, and audit building blocks exist | Backup, encryption-at-rest, retention, privacy review, and production operational controls are not proven by this code-only review. |

The exact workbook test-case trace is incomplete. Of the 48 expected positive/negative case IDs for ADN-001–009, XCT-001–005, and ADM-001–010, **21 IDs are attached to tests**: all 18 ADN cases and only three admissions cases (`TC-ADM-001-P`, `TC-ADM-002-P`, and `TC-ADM-004-N`). No XCT case IDs are attached to tests. The passing suite must not be interpreted as all workbook acceptance cases passing.

### Role and permission decision to revisit

The documented role mapping is `Admin` → `Academy Administrator` and `Registrar` → `Registrar / Admissions Officer`; the separate System Administrator role is assigned only through the CLI bootstrap command.

The System Administrator seeder grants broad catalogue permissions, including non-approval finance actions such as `Payments.Record`, `Invoices.Create`, and `Reports.Finance`. Approval actions are excluded. The workbook persona says the System Administrator has no operational finance approvals; confirm whether these non-approval finance actions are acceptable, and tighten the seed permissions if the intended boundary is no operational finance access.

## What was verified against the running local application

Swagger UI was opened at `http://127.0.0.1:8010/docs/api`; the OpenAPI document loaded and exposed 68 operations. The following requests were sent from Swagger or checked over HTTP:

| Request | Observed result | What this proves |
|---|---|---|
| `GET /up` | `200` | Laravel health route responds. |
| `GET /docs/api` | `200` | Swagger UI page is served. |
| `GET /docs/api/openapi.yaml` | Loaded by Swagger UI | The specification is served and the UI can parse/render it. |
| `GET /api/v1/auth/csrf-cookie` | `204`; `XSRF-TOKEN` and `laravel-session` cookie names were returned | CSRF/session bootstrap works on the local origin. Cookie values were not recorded. |
| `GET /api/v1/auth/user` without a login session | `401` with `Unauthenticated.` | Protected identity access is denied when unauthenticated. |
| `POST /api/v1/auth/register` with `{}` | `422`; required-field errors for first name, last name, email, password, and confirmation | The live registration route and backend validation execute. No account was created by this invalid request. |
| `POST /api/v1/auth/admissions/applications` with `{}` | `422`; required-field validation errors | The live route and request validation execute. This is not a successful application submission. |

The admissions public-submission feature flag reports **false** in the local Laravel configuration. The empty request still receives `422` because Laravel validates the Form Request before entering the controller where the feature-flag check is located. A complete valid submission was not attempted; the required MySQL admissions tables are missing. Consider enforcing the feature flag before Form Request validation so disabled public submissions consistently return `404`.

The read-only MySQL check found five user rows, with **zero active** and **zero email-verified** accounts. No password was available for a known test account. No login credentials were guessed, no user was created or modified, and no mail was sent. Therefore these flows remain unverified live:

- Successful login, session-cookie authentication, `/auth/user` returning `200`, and logout.
- Successful email verification/password recovery and actual SMTP delivery.
- Any protected administration or admissions operation that reads or writes the pending tables.

## Step-by-step Swagger check performed

These are the exact low-risk requests executed; they do not create or alter school records.

1. Start the server from the repository folder:

   ```powershell
   php artisan serve --host=127.0.0.1 --port=8010
   ```

2. Open `http://127.0.0.1:8010/docs/api`. The expected result is the School Management Portal Swagger page.
3. Expand **GET `/api/v1/auth/csrf-cookie`**, select **Try it out**, then **Execute**. The expected and observed result is `204`; the browser receives the XSRF and session cookies.
4. Expand **GET `/api/v1/auth/user`** and execute it without logging in. The expected and observed result is `401` with `{"message":"Unauthenticated."}`.
5. Expand **POST `/api/v1/auth/register`**, set the request body to `{}`, and execute. The expected and observed result is `422` with required-field errors. This is a validation check, not a successful registration.
6. Expand **POST `/api/v1/auth/admissions/applications`**, set the request body to `{}`, and execute. The observed result is `422` with required-field errors. This does not test a completed submission or the disabled-feature response.
7. Check `GET /up` separately; the observed result is `200`.

### How to finish an authenticated Swagger workflow

There is currently no active, verified local account with known test credentials, so a valid login test cannot be completed without preparing a local test account. Do not use production credentials. After the local database migrations and a dedicated test account have been safely prepared:

1. Keep Swagger and the API on the same host and port.
2. Expand **POST `/api/v1/auth/login`**, enter the test account's email and password, and execute. Expect `200` and a public `data` user object, with no bearer token in the response.
3. Execute **GET `/api/v1/auth/user`** in the same Swagger browser session. Expect `200` and that user's public profile.
4. Test only endpoints appropriate to the account's assigned role and campus.
5. Execute **POST `/api/v1/auth/logout`**. Expect `204`.
6. Execute **GET `/api/v1/auth/user`** again. Expect `401`.

Do not treat an unauthenticated `401` as proof that a protected business operation works. After migration, each workflow must be tested with authorized and unauthorized accounts and checked for the expected database result.

## Safe next steps

1. Confirm this is a disposable local development database and take a backup before applying the five pending migrations. No migration was run during this review.
2. Apply the migrations only after that confirmation; then seed the role/permission catalogue and provision a dedicated, active, verified local administrator through the documented bootstrap process.
3. Complete the Swagger session workflow above, then exercise the admin and admissions endpoints against MySQL, including their expected database changes and rejection cases.
4. Expand tests and documentation to cover all missing workbook IDs, especially XCT-001–005 and ADM-003–010.
5. Resolve the System Administrator finance-permission boundary and the feature-flag-before-validation behavior.
6. Implement the missing portal UI, responsive layouts, navigation, and legacy-import process as separate scoped work.
7. Implement AUTH-003 MFA and its lockout requirement; verify SMTP/queue delivery and the deployment privacy, backup, encryption, and retention controls.

Related references: [endpoint inventory](../api/endpoint-inventory.md), [OpenAPI specification](../api/openapi.yaml), [Postman beginner guide](POSTMAN_BEGINNER_GUIDE.md), and [API test cases](API_TEST_CASES.md).
