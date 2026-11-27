# Postman beginner guide

This guide uses the API that exists today: a session cookie, not an access token. It assumes Windows PowerShell, Laravel, and the local server.

## Simple definitions

- An **API** is a way for one program to ask another program to do something.
- A **request** is the question you send. A **response** is the server's answer.
- The **method** says what kind of action to take: GET reads, POST submits, PATCH updates selected details, and PUT replaces/changes a specific setting.
- A **URL** is the address of the operation.
- A **header** is extra information attached to a request, such as “expect JSON.”
- **JSON** is a structured way to write data using names and values.
- A **cookie** is a small browser/Postman-managed value that keeps your session signed in.
- A **status code** is a number describing the result: 2xx usually succeeded, 4xx usually means the request needs correction or authentication, and 5xx means a server problem.

## Install and prepare Postman

1. Download Postman from [postman.com/downloads](https://www.postman.com/downloads/), install it, and open the application.
2. Select **Workspaces → My Workspace** (or your current workspace).
3. Select **New → Collection** and name it `School Management Portal - Local`.
4. Create an environment named `SMP Local`.
5. Add variables:

   | Variable | Initial value |
   |---|---|
   | `base_url` | `http://127.0.0.1:8010` |
   | `spa_origin` | `http://localhost:5173` |

6. Select `SMP Local` in the environment picker.
7. Open the project's `.env` and ensure it contains the local server's host and port in both settings. For the example `8010` server, use:

   ```env
   APP_URL=http://localhost:8010
   SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8010,127.0.0.1,127.0.0.1:8010,localhost:5173,127.0.0.1:5173,::1
   ```

   Keep any additional approved local SPA domains already present in your file.
8. Clear configuration cache, then start the backend from the project directory:

   ```powershell
   php artisan config:clear
   php artisan serve --host=127.0.0.1 --port=8010
   ```

   Leave this PowerShell window open while testing.
9. Open a second PowerShell window in the project directory and run `php artisan queue:work database`. Leave it open while testing registration so queued verification mail is processed. The default local `log` mailer records the message; it does not send mail to an inbox.
10. For each request, use `{{base_url}}` at the start of the URL. Save it into the collection with **Save**.

## Set up the cookie session

1. Create a request named `Bootstrap CSRF cookies`.
2. Select **GET** and enter `{{base_url}}/api/v1/auth/csrf-cookie`.
3. Add `Accept` = `application/json`.
4. Select **Send**. Expect `204 No Content`; a blank response body is normal.
5. Use Postman's **Cookies** button for `127.0.0.1` and confirm cookies exist, including `XSRF-TOKEN` and the session cookie (default name `laravel-session`).
6. For POST, PUT, and PATCH requests sent as the first-party session client, add `X-XSRF-TOKEN` with the URL-decoded value of `XSRF-TOKEN`. Postman sends the session cookie from the same host automatically.
7. Add these headers to JSON requests:

   | Header | Value |
   |---|---|
   | `Accept` | `application/json` |
   | `Origin` | `http://localhost:5173` |
   | `Content-Type` | `application/json` (Postman usually sets this when Body → raw → JSON is selected) |

   The origin must be listed in `SANCTUM_STATEFUL_DOMAINS` for the cookie session flow.

## Create and run a request

1. In your collection, select **Add request** and give it a clear name.
2. Choose the HTTP method and enter the URL.
3. Add the headers shown for that endpoint.
4. If there is a JSON body, choose **Body → raw → JSON** and paste the complete example.
5. Select **Send**.
6. Read the status shown above the response body. Compare the body with the example in [API test cases](API_TEST_CASES.md).
7. Select **Save** so the request remains in the collection.

## Authentication in Postman

There is **no access token** returned by login. Keep using the same Postman cookie jar:

1. Bootstrap CSRF cookies.
2. Register or prepare an account, then send `POST /api/v1/auth/login`.
3. Postman retains the `laravel-session` cookie for the same host.
4. Use `GET /api/v1/auth/user` to confirm the session.
5. Send the current URL-decoded `XSRF-TOKEN` cookie in `X-XSRF-TOKEN` on state-changing requests.
6. Logout, then confirm the current-user endpoint returns `401`.

Do not put a session cookie into `Authorization: Bearer ...`; that is not this application's login flow.

## Phase A: test roles and permissions

These are protected administrator APIs. Registration does not grant a role, and there is no public API for making someone an administrator.

### Prepare the first administrator (one-time, in PowerShell)

1. Make sure a user account already exists, is active, and has verified its email address.
2. Do not try to bypass email verification if the account has not completed it.
3. In a PowerShell window opened in the project folder, run this command, replacing the example with that user's email:

   ```powershell
   php artisan app:bootstrap-system-administrator admin@example.test
   ```

4. Expect `System Administrator access has been assigned.` The command creates the approved default roles and permission catalogue as needed. It fails if no matching active, verified account exists or if a System Administrator has already been bootstrapped.
5. In Postman, bootstrap CSRF cookies and log in as that account using the same cookie jar.

Do not use the command as part of routine user registration. It is an operator-only bootstrap path and is intentionally not an HTTP endpoint.

### Try the role and permission requests

All examples use `{{base_url}}`, the same `Origin`, session cookie, and CSRF header set up earlier. Add `X-XSRF-TOKEN` for POST, PATCH, PUT, and DELETE requests.

1. **List default roles:** send `GET {{base_url}}/api/v1/admin/roles`. Expect a paginated `data` list with role names, assigned-user counts, and permission keys. The approved mapped labels include `Academy Administrator` and `Registrar / Admissions Officer`.
2. **Read the permission catalogue:** send `GET {{base_url}}/api/v1/admin/permissions`. Expect the workbook-supported modules and their actions.
3. **Clone a role:** send `POST {{base_url}}/api/v1/admin/roles/{registrar_role_id}/clone` with:

   ```json
   {
     "name": "Senior Registrar",
     "description": "A custom role cloned from the registrar defaults."
   }
   ```

   Expect `201`. The new role copies the source role's permissions. A role created with `POST /api/v1/admin/roles` instead starts with no permissions.
4. **Read a role's matrix:** send `GET {{base_url}}/api/v1/admin/roles/{role_id}/permissions`. Each action includes its permission key and whether it is granted.
5. **Replace a role's permissions:** send `PUT {{base_url}}/api/v1/admin/roles/{finance_officer_role_id}/permissions` with:

   ```json
   {
     "permissions": [
       "Dashboard.View",
       "Payments.View",
       "Invoices.Create",
       "Reports.View",
       "Reports.Finance"
     ]
   }
   ```

   This example removes `Payments.Record` from Finance Officer. Read the returned matrix and confirm that permission is no longer granted. An authenticated user without `Permissions.Manage` receives `403`; a manager cannot grant permissions they do not personally hold.
6. **Try the assigned-role delete guard:** send `DELETE {{base_url}}/api/v1/admin/roles/{role_id}` for a role assigned to a user. Expect `409` with the assigned-user count. Built-in roles cannot be deleted.

The repository does not yet include a live payments API or a frontend navigation interface. The feature test verifies the `Payments.Record` authorization middleware using a test-only route; UI behavior will be validated when the related frontend/payment feature is implemented.

## Phase A continuation: academy setup, settings, and audit logs

These steps use the same authenticated Postman session as the role tests. The API requires the new administration migrations to exist in the selected database. The automated tests run on SQLite; they do not migrate or verify the configured MySQL database. Before applying migrations locally, confirm `.env` targets a development database and take a backup. Only then run `php artisan migrate` from the project directory; do not run it against production without the normal release and backup approvals.

1. **Check the signed-in user and permissions:** send `GET {{base_url}}/api/v1/auth/user`. Use the one-time CLI bootstrap instructions above if an existing active, verified account does not yet have the System Administrator role.
2. **Read the academy profile:** send `GET {{base_url}}/api/v1/admin/academy-profile`. Before first setup, `data` is null.
3. **Set academy details and logo:** send `PUT {{base_url}}/api/v1/admin/academy-profile`. Select **Body → form-data**. Add `name` (Text), `currency` (Text, for example `NGN`), `timezone` (Text, for example `Africa/Lagos`), and optional `address`, `email`, `phone`, `registration_number`, `date_format`, and `letterhead`. Add `logo` as a **File**. Only PNG/JPG/JPEG up to 2 MB is accepted. Expect `200` and a `data.logo_url`.
4. **Create a campus:** send `POST {{base_url}}/api/v1/admin/campuses` with **Body → raw → JSON**:

   ```json
   {
     "name": "North Campus",
     "address": "10 School Road",
     "phone": "+2348000000000",
     "email": "north@example.test"
   }
   ```

   Expect `201`. Non-bootstrap campus managers are automatically assigned to a campus they create. Save its returned `data.id` for the room request.
5. **Create a room:** send `POST {{base_url}}/api/v1/admin/campuses/{campus_id}/rooms`:

   ```json
   {
     "name": "Lab 1",
     "capacity": 30,
     "type": "lab",
     "status": "active"
   }
   ```

   Room names must be unique within a campus. The API returns `409` if deactivation conflicts with future scheduled sessions.
6. **Create a department:** send `POST {{base_url}}/api/v1/admin/departments`:

   ```json
   {
     "name": "ICT",
     "description": "Information and communications technology"
   }
   ```

   `GET /api/v1/admin/departments` lists departments and their course/user counts. Deleting a department that is still in use returns `409`.
7. **Create an academic session:** send `POST {{base_url}}/api/v1/admin/academic-sessions`:

   ```json
   {
     "name": "2027/2028",
     "starts_on": "2027-01-01",
     "ends_on": "2027-12-31",
     "is_current": true
   }
   ```

   Session dates cannot overlap. Marking a different session current clears the prior session's current flag.
8. **Create an intake:** send `POST {{base_url}}/api/v1/admin/intakes`, using the session ID returned above:

   ```json
   {
     "academic_session_id": 1,
     "campus_id": 1,
     "name": "January 2027",
     "starts_on": "2027-01-15",
     "applications_open_at": "2026-10-01 00:00:00",
     "applications_close_at": "2026-12-15 23:59:59",
     "capacity": 80
   }
   ```

   The close time must be later than the open time. Campus-specific intakes are only visible to users assigned to that campus.
9. **Add a holiday:** send `POST {{base_url}}/api/v1/admin/academic-holidays` with `name`, `holiday_date` (`YYYY-MM-DD`), and optional `academic_session_id`/`campus_id`.
10. **Change a supported setting:** send `PUT {{base_url}}/api/v1/admin/settings/discount.approval_threshold_percent` with:

    ```json
    {
      "value": 15
    }
    ```

    Expect `200`, the saved setting, and an audit row. Setting values are validated against a fixed catalog; unsupported keys and notification-provider secrets are not accepted. The catalog is the source for future workflows; this API does not retroactively change posted financial records.
11. **Search audit logs:** send `GET {{base_url}}/api/v1/admin/audit-logs?entity_type=student&entity_id=456`. You can also filter by `user_id`, `module`, `action`, `from`, and `to`; `per_page` can be 25, 50, or 100.
12. **Export audit logs:** send `GET {{base_url}}/api/v1/admin/audit-logs/export` with the same filters. Expect a CSV download. The export itself is audited. Try `DELETE {{base_url}}/api/v1/admin/audit-logs/{id}`; it returns `405`, logs the attempt, and keeps the original row.

The backend has no responsive portal UI, applicant/student workflow, or production payment workflow in this repository yet. These manual API steps do not verify those frontend or operational acceptance criteria.

## Troubleshooting

- **Could not get a response:** confirm Laravel is still running and the URL uses the same port.
- **419 / CSRF error:** call the CSRF-cookie endpoint again, refresh the decoded XSRF cookie header, and retry with the same host.
- **401 Unauthenticated:** log in again and check that Postman has not switched host between `localhost` and `127.0.0.1`.
- **400 Secure transport required:** production is configured to require HTTPS; local HTTP is for local/testing only.
- **No email arrived:** confirm the queue worker is running. The local default is the `log` mailer, so inspect `storage/logs/laravel.log` after the job is processed; SMTP is not configured by default.
- **Reset link page is missing:** the backend has a reset endpoint but no frontend form. Submit the email's token to the reset API as shown in the test cases.

Continue in this order: [test data and execution order](TEST_DATA_AND_EXECUTION_ORDER.md), [API test cases](API_TEST_CASES.md), then [authentication scenarios](AUTHENTICATION_TESTING.md).
