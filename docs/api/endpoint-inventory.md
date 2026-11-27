# Endpoint inventory

This inventory was checked against Laravel's registered route table and the route/controller implementation. The application currently registers 68 versioned API operations; the Swagger document contains the same 68 operations. The full route table contains 75 rows, including seven web, documentation, health, storage, or development-tool routes. These counts describe registered code, not successful execution against the configured database. The current MySQL database is missing the RBAC, academy setup, user-campus, invitation, and admissions migrations. See the [live verification report](../testing/PROJECT_STATUS_AND_LIVE_API_VERIFICATION.md).

## Versioned authentication API

| ID | Module | Method and path | Purpose | Authentication / role | Inputs | Successful result | Status |
|---|---|---|---|---|---|---|---|
| API-001 | Session | `GET /api/v1/auth/csrf-cookie` | Start browser session and set CSRF cookies | Public; web/session middleware | None | `204`, session and XSRF cookies | Implemented; feature-tested |
| API-002 | Registration | `POST /api/v1/auth/register` | Create basic account and request email verification | Public; no role assigned | `first_name`, `last_name`, `email`, `password`, `password_confirmation` | `201`, `{message, data}` | Implemented; feature-tested |
| API-003 | Session | `POST /api/v1/auth/login` | Verify credentials and establish session | Public; local/testing bypass rules apply | `email`, `password` | `200`, `{data}` and session cookie | Implemented; feature-tested |
| API-004 | Session | `GET /api/v1/auth/user` | Read current signed-in identity | Session required; no role check | None | `200`, `{data}` | Implemented; feature-tested |
| API-005 | Session | `POST /api/v1/auth/logout` | Invalidate the current session | Session required; no role check | CSRF header for stateful session request | `204`, empty body | Implemented; feature-tested |
| API-006 | Registration | `GET /api/v1/auth/email/verify/{id}/{hash}` | Verify email and activate account | Public signed, expiring URL | Path `id`, `hash`; signed URL also has `expires`, `signature` | `200`, `{message}` | Implemented; feature-tested |
| API-007 | Registration / email change | `POST /api/v1/auth/email/verification-notification` | Resend account verification or pending-email confirmation | Public; generic response | `email` | `202`, generic `{message}` | Implemented; primary branches feature-tested |
| API-008 | Password recovery | `POST /api/v1/auth/password/forgot` | Request one-time reset email | Public | `email` | `202`, generic `{message}` | Implemented; feature-tested |
| API-009 | Password recovery | `POST /api/v1/auth/password/reset` | Set new password with email/token | Public | `email`, `token`, `password`, `password_confirmation` | `200`, `{message}` | Implemented; feature-tested |
| API-010 | Profile | `PATCH /api/v1/auth/profile` | Update name, phone, pending email, or private photo | Session required; no role check | Optional name/phone/email; multipart photo | `200`, `{message, data}` | Implemented; feature-tested |
| API-011 | Profile | `GET /api/v1/auth/profile/photo` | Stream the current user's private photo | Session required; no role check | None | `200`, image bytes | Implemented; feature-tested |
| API-012 | Profile | `PUT /api/v1/auth/password` | Change password using current password | Session required; no role check | `current_password`, `password`, `password_confirmation` | `200`, `{message}` | Implemented; feature-tested |
| API-013 | Email change | `GET /api/v1/auth/email/change/verify/{id}/{hash}` | Confirm and apply pending email | Public signed, 30-minute URL | Path `id`, `hash`; signed URL also has `expires`, `signature` | `200`, `{message}` | Implemented; feature-tested |

Authentication endpoints retain their existing session-cookie behavior. Registration does not assign a role.

## Versioned role and permission administration API

| ID | Module | Method and path | Purpose | Authentication / permission | Inputs | Successful result | Status |
|---|---|---|---|---|---|---|---|
| API-014 | Administration | `GET /api/v1/admin/roles` | List roles, permission keys, and assigned-user counts | Sanctum session; `Roles.Manage` | Optional pagination | `200`, paginated `{data}` | Implemented; feature-tested |
| API-015 | Administration | `POST /api/v1/admin/roles` | Create a custom role with no permissions | Sanctum session; `Roles.Manage` | `name`, optional `description` | `201`, `{data}` role | Implemented; feature-tested |
| API-016 | Administration | `GET /api/v1/admin/roles/{role}` | Read one role | Sanctum session; `Roles.Manage` | Role ID | `200`, `{data}` role | Implemented; feature-tested |
| API-017 | Administration | `PATCH /api/v1/admin/roles/{role}` | Update a custom role's name or description | Sanctum session; `Roles.Manage` | Optional `name`, `description` | `200`, `{data}` role | Implemented; feature-tested |
| API-018 | Administration | `DELETE /api/v1/admin/roles/{role}` | Delete an unassigned custom role | Sanctum session; `Roles.Manage` | Role ID | `204`, empty body | Implemented; feature-tested |
| API-019 | Administration | `POST /api/v1/admin/roles/{role}/clone` | Clone a role and its permissions | Sanctum session; `Roles.Manage` | `name`, optional `description` | `201`, `{data}` role | Implemented; feature-tested |
| API-020 | Administration | `GET /api/v1/admin/permissions` | Read available modules/actions | Sanctum session; `Permissions.Manage` | None | `200`, `{data.modules}` | Implemented; feature-tested |
| API-021 | Administration | `GET /api/v1/admin/roles/{role}/permissions` | Read a role's permission matrix | Sanctum session; `Permissions.Manage` | Role ID | `200`, `{data.role, data.modules}` | Implemented; feature-tested |
| API-022 | Administration | `PUT /api/v1/admin/roles/{role}/permissions` | Replace a role's permission assignments | Sanctum session; `Permissions.Manage` | `permissions` string array; empty array revokes all | `200`, updated matrix | Implemented; feature-tested |

Built-in roles are seeded from the approved workbook mapping. Custom role creation never accepts a permission or user assignment. An existing active, verified user receives the reserved System Administrator role only through `php artisan app:bootstrap-system-administrator <email>`; there is no public bootstrap endpoint. Campus scope is enforced on campuses, rooms, intakes, and holidays. User-role administration and the navigation UI remain pending.

## Academy setup, system settings, and audit API

| ID | Module | Method and path | Purpose | Authentication / permission | Inputs | Successful result | Status |
|---|---|---|---|---|---|---|---|
| API-023 | Academy profile | `GET /api/v1/admin/academy-profile` | Read academy branding | Sanctum session; `Settings.Manage` | None | `200`, `{data}` or null profile | Implemented; feature-tested |
| API-024 | Academy profile | `PUT /api/v1/admin/academy-profile` | Create/update name, logo, address, contact, currency, timezone, date format, and letterhead | Sanctum session; `Settings.Manage` | Multipart profile fields; PNG/JPG logo, max 2 MB | `200`, `{data}` | Implemented; feature-tested |
| API-025 | Campuses | `GET /api/v1/admin/campuses` | List campuses visible to the user | Sanctum session; `Settings.Manage`; campus scope | None | `200`, paginated `{data}` | Implemented; feature-tested |
| API-026 | Campuses | `POST /api/v1/admin/campuses` | Create a campus | Sanctum session; `Settings.Manage` | `name`, `address`, optional contact | `201`, `{data}` | Implemented; feature-tested |
| API-027 | Campuses | `GET /api/v1/admin/campuses/{campus}` | Read a campus | Sanctum session; `Settings.Manage`; campus scope | Campus ID | `200`, `{data}` | Implemented; feature-tested |
| API-028 | Campuses | `PATCH /api/v1/admin/campuses/{campus}` | Update a campus | Sanctum session; `Settings.Manage`; campus scope | Optional name, address, contacts, active state | `200`, `{data}` | Implemented; feature-tested |
| API-029 | Rooms | `GET /api/v1/admin/campuses/{campus}/rooms` | List rooms for an assigned campus | Sanctum session; `Settings.Manage`; campus scope | Campus ID | `200`, paginated `{data}` | Implemented; feature-tested |
| API-030 | Rooms | `POST /api/v1/admin/campuses/{campus}/rooms` | Create a room | Sanctum session; `Settings.Manage`; campus scope | Name, capacity, type, status | `201`, `{data}` | Implemented; feature-tested |
| API-031 | Rooms | `PATCH /api/v1/admin/campuses/{campus}/rooms/{room}` | Update a room | Sanctum session; `Settings.Manage`; campus scope | Optional room fields | `200`, `{data}`; `409` if future sessions conflict with deactivation | Implemented; feature-tested |
| API-032 | Academic sessions | `GET /api/v1/admin/academic-sessions` | List academic sessions | Sanctum session; `Settings.Manage` | None | `200`, paginated `{data}` | Implemented; feature-tested |
| API-033 | Academic sessions | `POST /api/v1/admin/academic-sessions` | Create a non-overlapping session | Sanctum session; `Settings.Manage` | Name, start/end dates, optional current/closed flags | `201`, `{data}` | Implemented; feature-tested |
| API-034 | Academic sessions | `PATCH /api/v1/admin/academic-sessions/{academic_session}` | Update a session | Sanctum session; `Settings.Manage` | Optional session fields | `200`, `{data}` | Implemented; feature-tested |
| API-035 | Intakes | `GET /api/v1/admin/intakes` | List global and campus-visible intakes | Sanctum session; `Settings.Manage`; campus scope | None | `200`, paginated `{data}` | Implemented; feature-tested |
| API-036 | Intakes | `POST /api/v1/admin/intakes` | Create an intake with an application window and capacity | Sanctum session; `Settings.Manage`; campus scope | Session, optional course/campus, name, start/window dates, capacity | `201`, `{data}` | Implemented; feature-tested |
| API-037 | Intakes | `PATCH /api/v1/admin/intakes/{intake}` | Update an intake | Sanctum session; `Settings.Manage`; campus scope | Intake fields | `200`, `{data}` | Implemented; feature-tested |
| API-038 | Academic holidays | `GET /api/v1/admin/academic-holidays` | List global and campus-visible holidays | Sanctum session; `Settings.Manage`; campus scope | None | `200`, paginated `{data}` | Implemented; feature-tested |
| API-039 | Academic holidays | `POST /api/v1/admin/academic-holidays` | Add a holiday | Sanctum session; `Settings.Manage`; campus scope | Name, date, optional session/campus | `201`, `{data}` | Implemented; feature-tested |
| API-040 | Departments | `GET /api/v1/admin/departments` | List departments with usage counts | Sanctum session; `Settings.Manage` | None | `200`, paginated `{data}` | Implemented; feature-tested |
| API-041 | Departments | `POST /api/v1/admin/departments` | Create a department | Sanctum session; `Settings.Manage` | Name, optional description | `201`, `{data}` | Implemented; feature-tested |
| API-042 | Departments | `PATCH /api/v1/admin/departments/{department}` | Update a department | Sanctum session; `Settings.Manage` | Optional name, description, active state | `200`, `{data}` | Implemented; feature-tested |
| API-043 | Departments | `DELETE /api/v1/admin/departments/{department}` | Delete an unused department | Sanctum session; `Settings.Manage` | Department ID | `204`; `409` while courses/users reference it | Implemented; feature-tested |
| API-044 | System settings | `GET /api/v1/admin/settings` | List supported, non-secret settings | Sanctum session; `Settings.Manage` | None | `200`, paginated `{data}` | Implemented; feature-tested |
| API-045 | System settings | `GET /api/v1/admin/settings/{key}` | Read one supported setting | Sanctum session; `Settings.Manage` | Catalog key | `200`, `{data}` | Implemented; feature-tested |
| API-046 | System settings | `PUT /api/v1/admin/settings/{key}` | Validate, update, and audit a supported setting | Sanctum session; `Settings.Manage` | `value` matching the key's validation rules | `200`, `{data}` | Implemented; feature-tested |
| API-047 | Audit | `GET /api/v1/admin/audit-logs` | Search immutable audit entries | Sanctum session; `Audit.View` | Optional user/module/action/entity/date filters; page size 25/50/100 | `200`, paginated `{data}` | Implemented; feature-tested |
| API-048 | Audit | `GET /api/v1/admin/audit-logs/export` | Export filtered entries as CSV | Sanctum session; `Audit.Export` | Same filters as search | `200`, streamed CSV; export action is logged | Implemented; feature-tested |
| API-049 | Audit | `PUT /api/v1/admin/audit-logs/{auditLog}` | Reject audit-log update attempt | Sanctum session; `Audit.View` | Audit ID | `405`; attempt is logged | Implemented; feature-tested |
| API-050 | Audit | `PATCH /api/v1/admin/audit-logs/{auditLog}` | Reject audit-log patch attempt | Sanctum session; `Audit.View` | Audit ID | `405`; attempt is logged | Implemented; feature-tested |
| API-051 | Audit | `DELETE /api/v1/admin/audit-logs/{auditLog}` | Reject audit-log deletion attempt | Sanctum session; `Audit.View` | Audit ID | `405`; attempt is logged | Implemented; feature-tested |

System-setting keys have explicit validation and do not accept provider credentials or arbitrary unknown names. Audit retention is initialized to 7 years and can be changed through the settings API. Settings are persisted and audited; downstream modules that enforce their operational effects are still being built.

## User invitations and admissions API

| ID | Module | Method and path | Purpose | Authentication / permission | Status |
|---|---|---|---|---|---|
| API-052 | Users | `GET /api/v1/admin/users` | List users visible to the actor | Sanctum session; `Users.Manage`; campus scope | Implemented; feature-tested |
| API-053 | Users | `POST /api/v1/admin/users` | Invite a user with role and optional campus assignments | Sanctum session; `Users.Manage` | Implemented; feature-tested |
| API-054 | Users | `POST /api/v1/admin/users/{user}/invitation` | Issue a replacement 48-hour invitation | Sanctum session; `Users.Manage` | Implemented |
| API-055 | Users | `PATCH /api/v1/admin/users/{user}/deactivate` | Deactivate a user and invalidate sessions | Sanctum session; `Users.Manage` | Implemented; feature-tested |
| API-056 | Users | `DELETE /api/v1/admin/users/{user}` | Soft-delete a user and invalidate sessions | Sanctum session; `Users.Manage` | Implemented; feature-tested |
| API-057 | Users | `POST /api/v1/admin/users/{user}/password-reset` | Request an active, verified user's password-reset email | Sanctum session; `Users.Manage` | Implemented |
| API-058 | Invitations | `GET /api/v1/auth/invitations/{user}/{token}` | Check a 48-hour invitation | Public; throttled | Implemented |
| API-059 | Invitations | `POST /api/v1/auth/invitations/accept` | Set password, verify email, and activate | Public; throttled | Implemented; feature-tested |
| API-060 | Admissions | `GET /api/v1/admin/applications` | Search/list applications with campus scope | Sanctum session; `Admissions.View` | Implemented |
| API-061 | Admissions | `POST /api/v1/admin/applications` | Create draft or submitted internal application | Sanctum session; `Admissions.Create` | Implemented; feature-tested |
| API-062 | Admissions | `GET /api/v1/admin/applications/{application}` | Read application, applicant, and status history | Sanctum session; `Admissions.View` | Implemented |
| API-063 | Admissions | `POST /api/v1/admin/applications/{application}/review` | Start review or request information | Sanctum session; `Admissions.Review` | Implemented; feature-tested |
| API-064 | Admissions | `POST /api/v1/admin/applications/{application}/decision` | Accept, reject, or waitlist with constrained transitions | Sanctum session; `Admissions.Decide` | Implemented; feature-tested |
| API-065 | Admissions | `POST /api/v1/admin/applications/{application}/admit` | Create student, enrollment, and admission letter | Sanctum session; `Admissions.Admit` | Implemented; feature-tested |
| API-066 | Admissions | `POST /api/v1/admin/applications/{application}/withdraw` | Withdraw an application | Sanctum session; `Admissions.Manage` | Implemented; feature-tested |
| API-067 | Admissions | `POST /api/v1/admin/applications/{application}/waitlist/promote` | Promote the first FIFO waitlist entry | Sanctum session; `Admissions.Decide` | Implemented; feature-tested |
| API-068 | Admissions | `POST /api/v1/auth/admissions/applications` | Optional public application submission | Public; feature flag disabled by default | Implemented; feature-tested |

Protected administrator APIs reject public role assignment to the bootstrap role. Public registration remains unprivileged.

## Other registered routes

Laravel's registered route count is tracked separately from the versioned business endpoints. The following are web, framework, or tooling routes, not versioned business API operations:

The current route table contains 75 entries: 68 versioned API operations and 7 web, framework, documentation, or development-tool routes.

| Route | Owner | Purpose / documentation status |
|---|---|---|
| `GET|HEAD /` | Application welcome route | Laravel welcome page; not an API operation |
| `POST /_boost/browser-logs` | Laravel Boost | Development tooling; not an SMP API operation |
| `GET|HEAD /storage/{path}` | Laravel filesystem | Local storage serving route; unrelated to the authenticated profile-photo route |
| `PUT /storage/{path}` | Laravel filesystem | Local storage handler; not part of the versioned API |
| `GET|HEAD /up` | Laravel | Health check |
| `GET /docs/api` | Application documentation | Swagger UI page; not a business API operation |
| `GET /docs/api/openapi.yaml` | Application documentation | OpenAPI specification; not a business API operation |

## Common request behavior

- Send `Accept: application/json`.
- For session-cookie requests, bootstrap cookies with `GET /api/v1/auth/csrf-cookie`. State-changing requests use `X-XSRF-TOKEN` from the URL-decoded `XSRF-TOKEN` cookie.
- Validation failures use HTTP `422` and Laravel's JSON `message` plus `errors` object.
- Protected operations return `401` when unauthenticated or after idle expiry.
- Route throttles may return `429`; login lockout is 15 minutes after five failed attempts and includes `Retry-After`.
- Production HTTP requests are rejected with `400`; use HTTPS in production.
- Administrative lists default to 50 records per page. Audit logs support user, module, action, entity, and date filters with 25/50/100 page sizes.

User invitations and administration, and the admissions workflow, have route/controller foundations and SQLite feature tests, but their migrations have not been applied to the configured MySQL database. Public admissions is disabled by default. Not implemented in this repository: a responsive portal UI (XCT-002), MFA, and a legacy-data import process (XCT-004). Operational backup, encryption, privacy, and retention guarantees require deployment configuration and verification. The automated test suite does not yet carry all workbook test-case IDs; see the [project status report](../testing/PROJECT_STATUS_AND_LIVE_API_VERIFICATION.md).

See the [OpenAPI specification](openapi.yaml) for request schemas and response examples.
