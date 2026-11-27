# API test cases

Use the same host, cookie jar, `Accept: application/json`, configured `Origin`, and CSRF setup described in the [Postman beginner guide](POSTMAN_BEGINNER_GUIDE.md). Examples use fictional `example.test` emails and local-only passwords.

| ID | Endpoint and purpose | Prerequisite / request | Success | Useful failure test |
|---|---|---|---|---|
| TC-API-001 | `GET /api/v1/auth/csrf-cookie` — start cookie session | No account required. Send GET. | `204`, blank body; cookies appear in Postman. | In production over HTTP, expect `400` secure-transport response. |
| TC-API-002 | `POST /api/v1/auth/register` — create basic account | JSON: `{"first_name":"Ada","last_name":"Lovelace","email":"ada@example.test","password":"StrongPass123","password_confirmation":"StrongPass123"}` | `201`, `message` and `data` with public fields only. Account is stored unverified/inactive. | Repeat the same email, omit a required field, use a malformed email, weak/mismatched password, or include `"role":"administrator"`; expect `422`. |
| TC-API-003 | `POST /api/v1/auth/login` — create sign-in session | Account exists. JSON: `{"email":"ada@example.test","password":"StrongPass123"}` | `200`, `data` user and cookies; no token in JSON. | Wrong credentials return generic `422`; five wrong attempts cause `429` and `Retry-After: 900`. Verified inactive accounts are rejected. The unverified local/testing bypass may allow unverified accounts. |
| TC-API-004 | `GET /api/v1/auth/user` — inspect current signed-in identity | Complete login in the same cookie jar. | `200`, `data` public user; response is `no-store`. | Clear cookies or logout first; expect `401`. |
| TC-API-005 | `POST /api/v1/auth/logout` — end session | Signed in; send CSRF header and cookie. | `204`, no body. | After logout, call TC-API-004; it must return `401`. Without a valid session expect `401`. |
| TC-API-006 | `GET /api/v1/auth/email/verify/{id}/{hash}` — activate verified account | Use the complete signed URL from the verification email/log. No bearer token. | `200`, “Email verified. Your account is now active.” | Alter/remove signature or use expired link; expect `403`. Unknown user may return `404`; throttled link requests return `429`. |
| TC-API-007 | `POST /api/v1/auth/email/verification-notification` — resend verification | JSON: `{"email":"ada@example.test"}` | `202`, generic message. For an eligible unverified address, notification is dispatched. | Unknown address returns the same `202` body; malformed email returns `422`. A pending email address sends its email-change notification. |
| TC-API-008 | `POST /api/v1/auth/password/forgot` — request reset | JSON: `{"email":"ada@example.test"}` | `202`, generic message; local log mailer writes link to Laravel log. | Try `unknown@example.test`; response remains identical. Malformed email returns `422`. |
| TC-API-009 | `POST /api/v1/auth/password/reset` — set password using reset token | JSON: `{"email":"ada@example.test","token":"TOKEN_FROM_EMAIL_OR_LOG","password":"NewStrong456","password_confirmation":"NewStrong456"}` | `200`, reset confirmation message; prior sessions invalidated. | Reuse/alter token, wait over 30 minutes, or submit weak/mismatched password; expect `422`. |
| TC-API-010 | `PATCH /api/v1/auth/profile` — update user details | Sign in. JSON: `{"first_name":"Ada","last_name":"Lovelace","phone":"+1 555 0100"}` | `200`, message plus public `data`. | Try another user's email, a duplicate/pending email, role/state fields, invalid photo type/size; expect `422`. An unverified current email attempting email change gets `403`. |
| TC-API-011 | `GET /api/v1/auth/profile/photo` — retrieve private photo | Signed in and photo previously uploaded. | `200`, image response; not JSON. | No photo returns `404`; no session returns `401`. |
| TC-API-012 | `PUT /api/v1/auth/password` — change current password | Signed in. JSON: `{"current_password":"StrongPass123","password":"NewStrong456","password_confirmation":"NewStrong456"}` | `200`, confirmation; other database sessions invalidated. | Wrong current password, weak new password, or mismatch returns `422`. |
| TC-API-013 | `GET /api/v1/auth/email/change/verify/{id}/{hash}` — apply pending email | Request email change while signed in; open complete signed link sent to new address. | `200`, new address verified and applied. | Alter/expire link or reuse after success; expect `403`. Unknown user may return `404`. |

## Phase A — Roles and permissions

Before using these endpoints, an operator must run the CLI bootstrap command for an existing active, email-verified account. This assigns the only reserved System Administrator role; there is no public role-assignment endpoint. Role and permission changes use the same Sanctum cookie session and CSRF header described above.

| ID | Endpoint and purpose | Prerequisite / request | Success | Useful failure test |
|---|---|---|---|---|
| TC-ADN-002-P | `POST /api/v1/admin/roles/{role}/clone` — clone Registrar | Sign in as bootstrapped System Administrator. Clone the `Registrar / Admissions Officer` role with `{"name":"Senior Registrar"}`. | `201`; the new custom role has the same permission keys. | Try a duplicate role name; expect `422`. |
| TC-ADN-002-N | `DELETE /api/v1/admin/roles/{role}` — protect a role in use | Assign the role to at least one user, then send DELETE. | Not a success case: expect `409` and a message containing the assigned-user count; role remains. | Try deleting an unassigned built-in role; expect `409`. |
| TC-ADN-003-P | `PUT /api/v1/admin/roles/{role}/permissions` — remove `Payments.Record` | As System Administrator, replace Finance Officer permissions with its current list excluding `Payments.Record`. | `200`; matrix reports `Payments.Record` as `granted: false`. Requests to a route protected by that permission return `403`. | Try to grant a permission the actor does not hold; expect `403` and an audit event. There is not yet a production payments route; the feature test exercises the middleware on a test-only route. |
| TC-ADN-003-N | `PUT /api/v1/admin/roles/{role}/permissions` — prevent privilege escalation | Sign in as `Registrar / Admissions Officer` and attempt to update Finance Officer permissions. | `403`; matrix is unchanged and the denied attempt is recorded in `audit_logs`. | As a limited permission manager, try granting a permission not held by the actor; expect `403` and an audit event. |

## Uploading a profile photo

For TC-API-010, choose **Body → form-data**. Add `profile_photo`, change its type from **Text** to **File**, and choose a JPG, JPEG, PNG, or WebP file no larger than 2 MB. Send as multipart form data; do not manually set a JSON `Content-Type`.

## How to decide pass/fail

Treat a test as passed only when the status, response body, and resulting behavior match the expected result. For example, after a password reset, test the new password with login and confirm the old session no longer accesses the protected user endpoint. A success status by itself is not enough.
