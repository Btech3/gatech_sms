# School Management Portal API

This folder documents the Laravel API currently present in this repository: authentication, role and permission administration, academy setup, system settings, audit logs, user invitations/administration, MFA, and an admissions workflow foundation. Route definitions and SQLite feature tests do not establish that an operation works against the current local MySQL database; the administration/admissions migrations and new MFA migration have not been verified there. A user-facing school portal is not implemented.

## Open the interactive documentation

1. Make sure `APP_URL` and `SANCTUM_STATEFUL_DOMAINS` in `.env` include the exact host and port used below. For port 8010, include `localhost:8010` and `127.0.0.1:8010` in the stateful domains, and set `APP_URL` to the chosen host/port.
2. Clear cached configuration after changing `.env`:

   ```powershell
   php artisan config:clear
   ```
3. Start the Laravel development server, for example:

   ```powershell
   php artisan serve --host=127.0.0.1 --port=8010
   ```
4. Open `http://127.0.0.1:8010/docs/api` in a browser using the same host and port as the API.
5. Use **Try it out** on the CSRF bootstrap and login operations, then use the protected operations in the same browser session.

Swagger UI loads its pinned assets from a public CDN, so the browser needs internet access. The API specification is available at `/docs/api/openapi.yaml`.

## Authentication model

The current implementation uses Sanctum's first-party session cookies. A normal-role login returns a public user resource; a configured sensitive-role login returns a short-lived MFA challenge (`202`) until TOTP verification succeeds. Neither flow returns a bearer access token. Call the CSRF-cookie endpoint before state-changing calls. Swagger UI handles the CSRF cookie/header in the browser; Postman instructions are in [the beginner guide](../testing/POSTMAN_BEGINNER_GUIDE.md).

Protected authentication operations require the logged-in session and MFA proof for configured sensitive roles. Administrative APIs enforce their respective permission keys on the backend. Campus assignments are enforced for campus, room, intake, holiday, user, and admissions access where applicable. The first System Administrator is assigned to an existing active, verified account using the CLI-only `app:bootstrap-system-administrator` command; public registration cannot assign roles. MFA code is covered by SQLite tests, but its migration and live database flow are not yet verified. The portal UI is not implemented. See [authentication details](authentication.md) and the [project status and live API verification report](../testing/PROJECT_STATUS_AND_LIVE_API_VERIFICATION.md).

## Versioning and base URL

Business API paths currently use `/api/v1/auth`. The OpenAPI server is relative to the origin that serves Swagger UI, so local and deployed hosts do not need hardcoded into the specification. Signed email links depend on the configured `APP_URL`; keep it aligned with the actual host, scheme, and port.

## Documents

- [Endpoint inventory](endpoint-inventory.md)
- [Authentication and current behavior](authentication.md)
- [Beginner testing guide](../testing/POSTMAN_BEGINNER_GUIDE.md)
- [Swagger UI usage](swagger-usage.md)
- [OpenAPI 3.1 specification](openapi.yaml)
- [Project status and live API verification](../testing/PROJECT_STATUS_AND_LIVE_API_VERIFICATION.md)
