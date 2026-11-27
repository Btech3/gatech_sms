# Authentication testing guide

This guide maps AUTH-001 through AUTH-004 to the actual code and API tests. It deliberately separates implemented behavior from requirements that are not in the repository.

## AUTH-001 — Registration and login

1. Start `php artisan queue:work database` in a second terminal for verification notifications.
2. Create an account with `POST /api/v1/auth/register`.
3. Confirm `201`, public user data, and no password/hash/token/role fields. The response means the verification email was queued, not delivered.
4. In `APP_ENV=local` or `testing`, the configured verification bypass can allow this account to sign in before email verification. Login does not mark the record verified or active.
5. Send `POST /api/v1/auth/login`. A successful response is `200` and sets a session cookie; it does not return an access token.
6. Send `GET /api/v1/auth/user` using the same cookie jar.
7. Try an unknown email, wrong password, a disabled account, and a locked account. Invalid credentials are generic; after five failures the lock lasts 15 minutes and returns `429` with `Retry-After`.
8. In production, the local unverified-account bypass is unavailable; HTTPS is also required.

Automated coverage: `AuthenticationTest`, `RegistrationTest`, and `EmailVerificationTest`.

## AUTH-002 — Password recovery

1. Send `POST /api/v1/auth/password/forgot` with a known email and then an unknown email. Both receive the same `202` response.
2. With the local `log` mailer, find the reset message in `storage/logs/laravel.log`; with real SMTP configured, use the delivered email.
3. Copy the reset token and submit it to `POST /api/v1/auth/password/reset` with the same email, strong confirmed password, and token.
4. Try the same token again; expect `422`. Use a new token and wait more than 30 minutes to test expiry.
5. Try a weak password and a confirmation mismatch; both should be rejected.
6. Sign in with the new password and verify old database-backed sessions no longer access protected endpoints.

The backend does not contain a user-facing reset form at `FRONTEND_URL/reset-password`; Postman callers submit to the reset API directly.

## AUTH-003 — Idle session and MFA

1. Sign in and call a protected route.
2. Wait 30 minutes without making authenticated requests; the next protected request should return `401` and clear the session.
3. Logout invalidates the shared server-side session, so tabs reusing that cookie should also lose access.

**MFA and the five-incorrect-code lockout are not implemented.** There is no MFA endpoint to test. Role-based MFA depends on an approved role/permission design and is out of current scope.

## AUTH-004 — Profile, password change, and logout

1. With an authenticated cookie, use `PATCH /api/v1/auth/profile` to update name/phone.
2. Upload a test image using multipart form data; fetch it with `GET /api/v1/auth/profile/photo`. Another account's session cannot use this endpoint to fetch the first account's photo because it always reads the authenticated user.
3. For email change, verify the existing email first, then submit the new address. Confirm the old email remains current until the new email's signed link is opened.
4. Use `PUT /api/v1/auth/password` with a correct current password and a new confirmed strong password. A wrong current password should produce `422`.
5. Use `POST /api/v1/auth/logout`, then call `GET /api/v1/auth/user`; the latter should return `401`.
6. Protected responses include `Cache-Control: no-store, private` to reduce stale browser-cache exposure.

## Automated coverage snapshot

The auth feature test files cover login and lockout, registration and validation, verification queue success/failure, verification links, password reset expiry/reuse/session invalidation, profile/photo/email change, password update, CSRF bootstrap, idle timeout, and logout. The latest full suite run recorded **56 passing tests and 287 assertions**. There is no automated Swagger UI browser test or MFA test.
