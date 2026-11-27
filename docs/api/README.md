# School Management Portal API

This folder documents the Laravel API currently present in this repository. It covers authentication, role and permission administration, academy setup, system settings, and audit-log APIs. It does not claim that admissions, billing, MFA, or user-administration features already exist.

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

The current implementation uses Sanctum's first-party session cookies. Login returns a public user resource and sets browser-managed cookies; it does **not** return a bearer access token. Call the CSRF-cookie endpoint before state-changing calls. Swagger UI handles the CSRF cookie/header in the browser; Postman instructions are in [the beginner guide](../testing/POSTMAN_BEGINNER_GUIDE.md).

Protected authentication operations require the logged-in session. Administrative APIs enforce their respective permission keys on the backend. Campus assignments are enforced for campus, room, intake, and holiday APIs. The first System Administrator is assigned to an existing active, verified account using the CLI-only `app:bootstrap-system-administrator` command; public registration cannot assign roles. MFA, user-role administration, and admissions are not implemented yet. See [authentication details](authentication.md).

## Versioning and base URL

Business API paths currently use `/api/v1/auth`. The OpenAPI server is relative to the origin that serves Swagger UI, so local and deployed hosts do not need hardcoded into the specification. Signed email links depend on the configured `APP_URL`; keep it aligned with the actual host, scheme, and port.

## Documents

- [Endpoint inventory](endpoint-inventory.md)
- [Authentication and current behavior](authentication.md)
- [Beginner testing guide](../testing/POSTMAN_BEGINNER_GUIDE.md)
- [Swagger UI usage](swagger-usage.md)
- [OpenAPI 3.1 specification](openapi.yaml)
- [Beginner testing guides](../testing/POSTMAN_BEGINNER_GUIDE.md)
