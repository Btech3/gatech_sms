# Authentication testing guide

This guide maps AUTH-001 through AUTH-004 to the actual code and API tests. It deliberately separates implemented behavior from requirements that are not in the repository.

## AUTH-001 — Registration and login

1. Start `php artisan queue:work database` in a second terminal for verification notifications.
2. Create an account with `POST /api/v1/auth/register`.
3. Confirm `201`, public user data, and no password/hash/token/role fields. The response means the verification email was queued, not delivered.
4. In `APP_ENV=local` or `testing`, the configured verification bypass can allow a normal account to sign in before email verification. Login does not mark the record verified or active. A sensitive-role account must still have a verified email to complete its MFA challenge.
5. Send `POST /api/v1/auth/login`. A normal account receives `200`; an account with a configured MFA-required role receives `202` and must complete MFA before it has an authenticated session. Login does not return an access token.
6. For a sensitive role, complete the MFA steps below. For a normal role, send `GET /api/v1/auth/user` using the same cookie jar.
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

### Authenticator-app TOTP

The configured sensitive roles are `System Administrator`, `Academy Administrator`, `Registrar / Admissions Officer`, `Finance Officer`, and `Academy Head`. The list can be changed through `AUTH_MFA_REQUIRED_ROLES`; this is server configuration, not a public request field.

1. Prepare a dedicated, active, verified test account with one of those roles. Bootstrap or assign roles only through the approved administrator process. Do not use a production account or share its password.
2. Bootstrap CSRF and log in. Expect `202` with `data.enrollment_required: true`, not an authenticated user profile.
3. Keep the same Postman cookie jar. Send `POST /api/v1/auth/mfa/enrollment/start` with an empty JSON object. Expect `200` with a secret, `otpauth://` provisioning URI, and an expiry about five minutes away.
4. In an authenticator app, choose **enter setup key manually** and enter the returned secret. Do not paste the secret or provisioning URI into tickets, chat, logs, or screenshots.
5. Send `POST /api/v1/auth/mfa/enrollment/confirm` with `{"code":"123456"}`, replacing the example with the current six-digit code from the authenticator. Expect `200`, the public user profile, and eight one-time `recovery_codes`. Store these securely; they are not returned again.
6. Send `POST /api/v1/auth/logout`, then log in again. Expect `202` with `data.enrollment_required: false`.
7. Send `POST /api/v1/auth/mfa/verify` with the current authenticator code. Expect `200`, then confirm `GET /api/v1/auth/user` returns `200` in the same cookie session.
8. A used TOTP time step cannot be replayed. A malformed or invalid code returns `422`; five invalid codes lock the account for 15 minutes and return `429` with `Retry-After: 900`.
9. To test recovery, start a new login challenge and send one unused 32-character recovery code to `POST /api/v1/auth/mfa/recovery`. Expect `200`; the same recovery code must then fail with `422`.
10. A challenge expires after five minutes. Start a new password login if an enrollment or verification request returns `401`.

The same cookie and CSRF requirements apply to these state-changing endpoints. Never enable privileged production access until the MFA migration has been reviewed and applied to the intended database and the enrollment, recovery, replay, and lockout workflow has passed in staging.

## AUTH-004 — Profile, password change, and logout

1. With an authenticated cookie, use `PATCH /api/v1/auth/profile` to update name/phone.
2. Upload a test image using multipart form data; fetch it with `GET /api/v1/auth/profile/photo`. Another account's session cannot use this endpoint to fetch the first account's photo because it always reads the authenticated user.
3. For email change, verify the existing email first, then submit the new address. Confirm the old email remains current until the new email's signed link is opened.
4. Use `PUT /api/v1/auth/password` with a correct current password and a new confirmed strong password. A wrong current password should produce `422`.
5. Use `POST /api/v1/auth/logout`, then call `GET /api/v1/auth/user`; the latter should return `401`.
6. Protected responses include `Cache-Control: no-store, private` to reduce stale browser-cache exposure.

## Automated coverage snapshot

The authentication feature tests cover login and lockout, registration and validation, verification queue success/failure, verification links, password reset expiry/reuse/session invalidation, profile/photo/email change, password update, CSRF bootstrap, idle timeout, logout, TOTP enrollment and verification, replay prevention, one-time recovery codes, and MFA lockout. Test counts must be taken from the latest completed full-suite run in the [live verification report](PROJECT_STATUS_AND_LIVE_API_VERIFICATION.md); these are SQLite automated results, not proof of a live MySQL deployment. There is no automated Swagger UI browser test.
