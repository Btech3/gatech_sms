# Test data and execution order

Use fictitious data and a local/test database only. Do not test with production accounts, school records, private contact details, or production credentials.

## Before starting

1. Confirm Laravel points to a disposable local MySQL/MariaDB database and not production.
2. Run `php artisan migrate:status` to see which migrations are pending. The current reviewed database has five pending administration/admissions migrations.
3. Back up the local database before applying pending migrations. Run `php artisan migrate` only after confirming the target and backup; never run it against production as part of this test guide.
4. Keep the local mailer as `log` until a private SMTP configuration is ready.
5. Start the server and use one consistent origin and port, for example `http://127.0.0.1:8010`.
6. In a second terminal, start `php artisan queue:work database` so queued verification emails are processed.
7. Bootstrap CSRF cookies and keep one Postman cookie jar for the test sequence.

## Recommended sequence

1. **Bootstrap:** `GET /api/v1/auth/csrf-cookie`.
2. **Register:** create a unique fictional address such as `tester-2026-11-26@example.test`. A `201` response means the verification email was queued, not delivered. Save the exact email and password in a local test note, not in this repository.
3. **Check registration state:** expect inactive/unverified in the database; local/testing bypass only affects login permission and does not change these fields.
4. **Login:** use `POST /api/v1/auth/login`; keep the same cookie jar.
5. **Current identity:** use `GET /api/v1/auth/user`.
6. **Profile:** update name/phone and upload a disposable test photo. Retrieve it through `/profile/photo`.
7. **Password change:** test current-password rejection first, then a valid change. Use the same signed-in session and confirm a second session is invalidated if you created one.
8. **Email verification/change:** use the log link or a configured test mailbox. Verify the current address before testing a change to a second fictional address.
9. **Forgot/reset:** request a reset, copy the token, change the password, verify token reuse fails, then log in with the new password.
10. **Logout:** sign out and verify `/user` returns `401`.
11. **Idle timeout:** use a fresh login, then wait 30 minutes without calling an authenticated route and verify the next protected request is rejected.

The current MySQL database has five pending migrations and no active, verified user accounts. A successful sign-in test therefore requires a safely migrated local database and a dedicated local test account. For the live Swagger results and remaining blockers, see [Project status and live API verification](PROJECT_STATUS_AND_LIVE_API_VERIFICATION.md). For request-by-request scenarios, use [API test cases](API_TEST_CASES.md).

## Safe cleanup

- Prefer disposable test addresses and the PHPUnit test database.
- Do not delete all users or truncate tables in a shared development database.
- For manual testing, delete only the specific test account and its own session/photo after confirming the email address and record belong to your test.
- Avoid `migrate:fresh` on any database containing data you need; it drops and recreates tables.
- PHPUnit uses database-refreshing test traits for isolated test data; run `php artisan test --compact`.

## Example data

| Field | Example |
|---|---|
| First name | Ada |
| Last name | Lovelace |
| Email | `ada-test@example.test` |
| Initial password | `StrongPass123` |
| New password | `NewStrong456` |
| Phone | `+1 555 0100` |

These are sample values only. Use a fresh email for each manual registration test because email uniqueness is enforced.
