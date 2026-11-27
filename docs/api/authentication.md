# Authentication and account behavior

## How sign-in works

The backend uses Laravel Sanctum's **first-party SPA session-cookie** flow. It does not currently return a personal access token or JWT from login.

1. Call `GET /api/v1/auth/csrf-cookie` to receive the session and XSRF cookies.
2. Send `POST /api/v1/auth/login` with email and password, retaining the same cookie jar.
3. The server returns the public user under `data` and rotates the session cookie.
4. Send that cookie with protected calls. For state-changing calls, also send the URL-decoded `XSRF-TOKEN` cookie as `X-XSRF-TOKEN`.
5. `POST /api/v1/auth/logout` invalidates the session; subsequent protected requests should return `401`.

The default session cookie name is `laravel-session`; it can be changed with `SESSION_COOKIE`. Do not copy it into a bearer `Authorization` header.

## Implemented behavior

### AUTH-001 — Login and registration

- Login accepts `email` and `password`, compares the secure stored hash, and returns a generic invalid-credentials response.
- Five consecutive incorrect attempts lock the account for 15 minutes. A locked account remains blocked even when the correct password is entered during the lock.
- Deactivated accounts are rejected.
- Registration requires first name, last name, unique email, and a confirmed password of at least eight characters with uppercase, lowercase, and a number.
- Public registration rejects role and activation/verification fields; new records are created inactive and unverified.
- Registration queues its verification email in the same database transaction. Keep the queue database connection shared with the application database (`DB_QUEUE_CONNECTION` unset or equal to `DB_CONNECTION`) for this atomic behavior. A successful `201` means the notification is queued, not delivered; run `php artisan queue:work database` to process local mail. If queue insertion fails, registration rolls back instead of leaving a duplicate-blocking account.
- In `local` and `testing` only, `AUTH_ALLOW_UNVERIFIED_LOGIN=true` permits login to an unverified, inactive account for local testing. This does not update the stored verification or activation state. Production still requires verified and active accounts.

### AUTH-002 — Password recovery

- `POST /api/v1/auth/password/forgot` returns the same `202` message for a known or unknown email.
- Laravel stores reset tokens hashed. The configured expiry is 30 minutes; reset is one-use and uses the same password strength/confirmation policy as registration.
- Successful reset changes the remember token and deletes database-backed sessions for the account.
- The backend has no password reset page. Its generated email URL points to `FRONTEND_URL/reset-password`; testers can use the emailed/logged token directly with `POST /api/v1/auth/password/reset`.

### AUTH-003 — Idle sessions and MFA

- Authenticated API requests enforce 30 minutes of inactivity and return `401` after expiry.
- Logout invalidates the session server-side, so other tabs sharing that session should also lose access.
- **MFA and five-attempt MFA lockout are not implemented.** There is no MFA endpoint, persistence model, or MFA test coverage.

### AUTH-004 — Profile and logout

- A signed-in user can update first name, last name, phone, and upload a private JPG/JPEG/PNG/WebP photo up to 2 MB.
- Email changes are stored as pending and only applied after the new address opens the signed 30-minute verification link. The existing email must already be verified to request a change.
- Password change requires the current password and invalidates other database-backed sessions.
- Authenticated responses are marked `Cache-Control: no-store, private`.
- No frontend user interface is included in this backend.

## Authorization and roles

The application now stores roles, permissions, role assignments, and audit events. The protected `/api/v1/admin` role and permission endpoints enforce `Roles.Manage` or `Permissions.Manage` on the server. Custom roles start with no permissions; cloning copies source permissions only when the acting administrator is permitted to grant each one. The permission matrix is queried from the database on each request, so changes take effect immediately.

The approved workbook mapping seeds `Admin` as **Academy Administrator** and `Registrar` as **Registrar / Admissions Officer**. Instructor, Finance Officer, and Academy Head retain their persona names. System Administrator is a separate reserved role; it is never assignable through registration or the role API. Use the CLI-only `php artisan app:bootstrap-system-administrator <email>` command to assign it to one existing active, verified account. The command seeds the default role and permission catalogue and refuses to run again after an administrator has been bootstrapped.

There is no frontend in this repository, so the role-driven navigation UI is not implemented. User-role administration, campus scoping, object-level data boundaries, and the full ADN-009 audit-log search/retention interface remain for later phases. The audit table currently records role and permission changes plus denied authorization attempts.

## Email delivery and testing

The checked-in configuration defaults to Laravel's `log` mailer. In local development, start `php artisan queue:work database` to process queued verification mail; content and links are written to `storage/logs/laravel.log`, not delivered externally. Verification notifications retry up to three times with backoff and are recorded in `failed_jobs` after exhausting retries. Real delivery requires configuring SMTP privately in `.env`; do not commit credentials.

## Known compatibility note for OpenAPI tools

Swagger UI's browser sends the session cookie automatically after a successful login in the same UI origin. The UI requests CSRF cookies and copies the XSRF cookie into the required request header. There is no bearer token to paste into an `Authorization` field. Postman uses its cookie jar plus the `X-XSRF-TOKEN` header.
