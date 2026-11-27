# Production Release Runbook

This runbook covers a safe release of the Laravel API backend. It is an operations guide, not evidence that a production deployment has occurred.

## Current release boundary

- No production deployment or production/MySQL database connection, migration, or seeding was performed for this work. Automated tests used their isolated SQLite test database.
- The database target for the current local `.env` is not confirmed. Do not use it as a production target.
- The last recorded local database inspection found five pending application migrations before the MFA migration was added. Re-check the actual staging and production targets separately; no current MySQL migration count is claimed.
- The repository does not include the portal frontend or a legacy-data source/import specification.
- Authenticator-app TOTP MFA is implemented in code and covered by SQLite feature tests. Its new user-table migration has not been applied to MySQL, and enrollment, recovery, lockout, and session enforcement have not been verified in staging or production. Do not enable sensitive production workflows until the migration is reviewed and the complete flow passes in staging.
- A prior `composer audit` reported five advisories affecting Symfony HTTP Kernel, Mailer, and Routing. Re-run the audit against the exact release lock file, assess the affected versions and exposure, and resolve or formally mitigate the findings before production.
- Public admissions must remain disabled until CAPTCHA, privacy notice, and legal-policy integrations are ready.

## 1. Prepare and review a release candidate

1. Select the exact branch, commit, PHP version, and deployment target. Confirm the target database, account owner, and environment with the deployment owner; never infer these from a developer `.env`.
2. Confirm CI passes the PHPUnit suite, formatting checks, dependency security review, and OpenAPI consistency checks for the exact release commit.
3. Confirm no populated `.env`, passwords, keys, verification URLs, or production data are included in the release.
4. Build dependencies from the committed lock file in the release environment:

   ```powershell
   composer install --no-dev --prefer-dist --optimize-autoloader
   composer check-platform-reqs
   ```

5. Test the same release artifact in staging before production. Staging must use isolated credentials and sanitized data.

## 2. Confirm the target and protect its data

1. Provision the intended MySQL database and a least-privilege application account through the hosting provider or DBA.
2. Confirm the release process points to that environment without printing secrets. On the target, inspect the migration status before changing schema:

   ```powershell
   php artisan migrate:status
   ```

3. Review the pending migrations and generated SQL in staging. The current repository adds RBAC/audit, academy setup, campus assignments, invitations, admissions tables, and MFA fields on `users` in `2026_11_27_214613_add_mfa_authentication_fields_to_users_table.php`. Several `down()` methods drop tables or columns; do not use rollback commands as a routine recovery plan.
4. Take an encrypted database snapshot and, where supported, a separate logical backup. Record the backup identifier and retention period.
5. Restore the backup to a separate staging database and verify that the application can read the restored schema. A backup that has not been restore-tested is not a verified recovery plan.

## 3. Set production configuration through a secret manager

Use the platform's environment/secret manager; do not commit a production `.env`.

Required checks:

- `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` is the canonical HTTPS origin.
- Preserve the existing `APP_KEY`; do not run `key:generate` against an established deployment.
- `DB_CONNECTION=mysql` and the host, database name, username, and password identify only the approved target.
- Set `SESSION_SECURE_COOKIE=true`. Set the session domain and Sanctum stateful domains to the actual browser/API hosts; do not include URL schemes in the stateful-domain list.
- Set `AUTH_ALLOW_UNVERIFIED_LOGIN=false`.
- Set `AUTH_MFA_REQUIRED_ROLES` to the organization-approved sensitive role names. The current defaults are `System Administrator`, `Academy Administrator`, `Registrar / Admissions Officer`, `Finance Officer`, and `Academy Head`; verify they match the seeded role names before release.
- Keep `ADMISSIONS_PUBLIC_ENABLED=false` until the missing public-submission abuse/privacy controls are deployed and reviewed.
- Configure SMTP credentials and `MAIL_FROM_ADDRESS` in the secret manager. Do not use the local `log` mailer for production.
- Configure the queue driver and a supervised worker. If using the database queue, verify the required queue tables exist before enabling workers.
- Configure persistent, access-controlled logging, monitoring, encrypted storage/backups, and an incident contact.

Apply configuration caching only after the release environment has the final values, then restart long-running web and queue processes so they use the same release and configuration.

## 4. Apply schema and bootstrap access deliberately

1. In staging, rehearse the migration and smoke-test the release. Obtain explicit production change approval and confirm the backup is restorable.
2. Run migrations once as a release operation, not independently on every web server:

   ```powershell
   php artisan migrate --force
   ```

   Never use `migrate:fresh`, `migrate:refresh`, `db:wipe`, or an unreviewed destructive rollback on a database containing real records.
3. Create or verify one named user account through the approved invitation/identity process. It must already be active and email-verified.
4. Bootstrap the reserved administrator exactly once using the application command:

   ```powershell
   php artisan app:bootstrap-system-administrator admin@example.org
   ```

   Replace the example with the approved account. The command checks that the account exists, is active and verified, and that no bootstrap administrator already exists. It seeds the role catalogue and records the bootstrap action. Do not grant the role through public registration or by editing the database manually.
5. If an existing deployment already has a bootstrap administrator, the one-time command intentionally refuses to run. After an approved backup and change review, synchronize the revised role catalogue with:

   ```powershell
   php artisan db:seed --class="Database\Seeders\RolePermissionSeeder" --force
   ```

   Record this privileged permission change in the release/change record and verify the resulting grants. The seeder excludes the `Payments`, `Invoices`, `Finance`, `Refunds`, and `Discounts` modules from the System Administrator role.
6. Before sensitive administration is used, sign in with the bootstrapped administrator. Expect a `202` MFA challenge, enroll an authenticator app, verify a current TOTP code, and store the one-time recovery codes in an approved password manager. In staging, also verify that replayed codes are rejected and five incorrect codes trigger a 15-minute lockout.
7. Verify the migration status, bootstrap audit event where applicable, role permissions, MFA enrollment, and application health.

## 5. Smoke-test the deployed application

Use a dedicated staging account first, then the approved production administrator account. Do not paste credentials or cookie values into tickets or logs.

1. Request `GET /up`; expect `200`.
2. Open Swagger on the same HTTPS host as the API. Request the CSRF cookie and confirm the browser receives the session/XSRF cookies with secure attributes.
3. Log in using the session-cookie flow. A normal role expects `200`; an MFA-required role expects `202` and must complete enrollment or TOTP verification before it is authenticated. No bearer token is returned.
4. Request `GET /api/v1/auth/user`; confirm it returns only the account's public profile after MFA completion. Before MFA completion, protected requests must return `401`.
5. For a sensitive role, verify a fresh TOTP succeeds, replay fails, a recovery code works once, and five invalid codes lock the account for 15 minutes.
6. Check one endpoint the account is authorized to use and one it is not authorized to use. Confirm campus scope is respected.
7. Log out, then request the protected current-user endpoint again; expect `401`.
8. In staging, verify a queued email reaches the controlled test inbox and that failed queue jobs are observable. Never test with production user credentials or send test mail to real students.
9. With public admissions disabled, submit an empty body to `POST /api/v1/auth/admissions/applications`; expect `404`, not validation details. Do not enable it until the CAPTCHA and legal-policy integrations are present.

For detailed beginner-friendly request setup, use [the Postman guide](POSTMAN_BEGINNER_GUIDE.md) and [the API test cases](API_TEST_CASES.md). A successful SQLite test is not a substitute for these environment-specific checks.

## 6. Release monitoring and recovery

- Monitor HTTP error rates, authentication failures, database health, queue depth, failed jobs, email delivery, disk capacity, and Laravel/application logs.
- After deploying new code, restart queue workers so they load the new release. Inspect failed jobs before retrying them.
- Keep the pre-release database snapshot until the agreed recovery window expires.
- Prefer rolling back application code only when that version is compatible with the forward-migrated schema.
- Do not automatically run `migrate:rollback`: it can drop application data or newly created tables. A database restore requires incident-owner approval, a verified restore point, and a documented record-loss assessment.
- Record the release commit, migration outcome, smoke-test results, bootstrap account owner, and any rollback decision without recording secrets or personal data.

## Release completion criteria

Do not declare production-ready until staging and production checks are recorded, backups have a tested restore, mail and queues work, HTTPS/session behavior is verified, the MFA schema is deployed, the full sensitive-role MFA workflow passes in staging, and all blocking tests pass. No production action was taken as part of authoring this runbook.
