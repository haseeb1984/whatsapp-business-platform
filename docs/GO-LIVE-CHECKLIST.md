# Production Go-Live Checklist

This is an evidence-based release gate for the Hostinger Business + Neon PostgreSQL deployment. A checked item must have a dated evidence record; a passing GitHub Actions run alone does not prove a live provider or hosting environment works.

## Release decision

- Release candidate commit: `<record exact main SHA>`
- Environment: `staging` / `production`
- Test window (UTC): `<start> — <end>`
- Operator / reviewer: `<names>`
- Decision: `NOT READY` until every mandatory gate below is accepted.
- Production deployment authorization: must be explicitly granted by the owner. This checklist does not authorize deployment.

## 1. Repository and CI

- [ ] `main` contains the reviewed release commit; `work` is synchronized to it.
- [ ] No unintended open pull requests or temporary working branches remain.
- [ ] Full CI is green on the exact release commit: SQLite and PostgreSQL 16 feature suites, migration rollback/replay on both, Composer validation/audit, npm production audit, `npm ci`, Vite production build, and route-chunk guard.
- [ ] Dependency lockfiles are committed and clean locked installs succeed.
- [ ] Review the release diff for secrets, debug code, test-only bypasses, and unexpected generated files.
- Evidence: PR URL, merge SHA, Actions run URL, and reviewed diff.

## 2. Neon PostgreSQL

Perform against a non-production/staging Neon branch first, then repeat the connection checks against the intended production database.

- [ ] Create a least-privilege application role; do not use the Neon owner/admin role for runtime traffic.
- [ ] Store the connection string only in the hosting secret/environment manager. Never paste it into tickets, chat, logs, screenshots, or Git.
- [ ] Confirm `DB_CONNECTION=pgsql`, `DB_URL` is set, and `DB_SSLMODE=require` (or a stricter verified mode).
- [ ] From the actual Hostinger runtime, verify DNS/network connectivity and TLS-authenticated database access using the application configuration.
- [ ] Run `php artisan migrate:status`; review pending migrations before applying any.
- [ ] Take a database backup/snapshot before migration; run `php artisan migrate --force` only after approval and record output.
- [ ] Verify required tables, indexes, foreign keys, queue tables, and failed-job storage.
- [ ] Exercise create/read/update/delete on a disposable tenant and verify tenant-scoped records cannot be read across tenants.
- [ ] Confirm connection limits, pooler/direct endpoint choice, statement timeout and Neon compute suspend/resume behavior meet expected traffic.
- Evidence: sanitized connectivity result, migration status/output, schema verification, backup identifier, and test tenant cleanup record. Do not record credentials.

## 3. Hostinger runtime

- [ ] Confirm the exact hosting plan, PHP CLI version (8.3+), PHP-FPM version, memory/time limits, process limits, disk quota, inode quota and cron frequency.
- [ ] Confirm required PHP extensions: `pdo_pgsql`, `curl`, `openssl`, `mbstring`, `xml`, `ctype`, `fileinfo`, `tokenizer`, `bcmath`, `intl` if required by locale behavior.
- [ ] Confirm application code is outside the public document root and only Laravel's `public/` directory is web-accessible.
- [ ] Confirm `.env` is outside public access, permissions are restrictive, and `APP_KEY` is stable and backed up securely.
- [ ] Confirm `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, secure session cookies, persistent session/cache drivers, and SMTP configuration.
- [ ] Run `php artisan platform:preflight --strict` from the deployed application directory. Resolve every warning; do not paste environment values into the evidence.
- [ ] Run `php artisan route:list`, `php artisan schedule:list`, and `php artisan about`; verify expected routes, scheduled tasks and runtime configuration.
- [ ] Verify storage and `bootstrap/cache` are writable by the correct runtime user, without making the whole application writable.
- [ ] Verify HTTPS certificate, redirect behavior, canonical host, webhook route reachability, and application error pages.
- Evidence: sanitized command outputs, PHP/extension inventory, hPanel cron configuration screenshots with secrets hidden, and document-root verification.

## 4. Scheduler and database queue

The shared-hosting model uses a bounded cron worker plus Laravel scheduler. They are separate responsibilities.

- [ ] Configure the bounded worker only after migrations and queue storage are verified:
  `php artisan queue:work database --stop-when-empty --max-time=20 --tries=3 --sleep=1 --timeout=45`
- [ ] Configure scheduler invocation once per minute:
  `php artisan schedule:run`
- [ ] Confirm the real PHP CLI binary and application path; do not copy example paths literally.
- [ ] Confirm `retry_after` exceeds worker timeout and the WhatsApp delivery job timeout remains below `retry_after`.
- [ ] Confirm scheduler overlap locks work with the configured cache store; do not use the array cache in production.
- [ ] Verify queue jobs are consumed, failed jobs are visible, and the platform operations dashboard reports queue age/failures.
- [ ] Test worker termination and stale-job recovery in staging. Verify unknown outbound delivery is not blindly resent.
- [ ] Measure cron execution duration and backlog under expected load; confirm Hostinger process limits do not terminate jobs unexpectedly.
- Evidence: cron schedule, `schedule:list`, worker logs with sensitive payloads removed, queue dashboard snapshot, and recovery test record.

## 5. Meta WhatsApp Cloud API

Use a Meta test app/number first. Production onboarding is a separate approval gate.

- [ ] Confirm Meta app ID, app secret, webhook verify token and Graph API version are configured in the secret manager.
- [ ] Verify webhook subscription and challenge verification on the deployed HTTPS endpoint.
- [ ] Send a signed test webhook and verify invalid signatures are rejected.
- [ ] Replay the same event and verify idempotency: one customer/conversation/message side effect.
- [ ] Verify inbound text/media events are persisted before acknowledgement and processed asynchronously.
- [ ] Send an in-window test message and an approved-template test message; confirm provider response and delivery-status webhook correlation.
- [ ] Verify 24-hour policy enforcement and that unapproved templates/free-form messages are blocked outside the customer-service window.
- [ ] Test provider timeout/ambiguous acceptance; verify status becomes unknown and automatic resend is blocked.
- [ ] Confirm access tokens and app secrets are encrypted at rest and never appear in logs or UI.
- [ ] Confirm Meta business verification, phone-number registration, display-name approval, messaging limits and production access are complete before real customer onboarding.
- Evidence: sanitized webhook/send test IDs, Meta dashboard status, approved template name/language, and delivery callback evidence.

## 6. Google Calendar

- [ ] Configure OAuth client ID/secret and exact HTTPS redirect URI.
- [ ] Confirm consent screen, scopes and publishing/test-user configuration match intended rollout.
- [ ] Complete OAuth with a dedicated test account; verify state/PKCE and tenant binding.
- [ ] Verify token refresh, revocation, calendar listing and writable-calendar selection.
- [ ] Create, update, cancel and reconcile a test appointment; verify deterministic event identity prevents duplicate events.
- [ ] Simulate provider timeout/ambiguous event creation and confirm reconciliation does not create a duplicate.
- [ ] Verify each tenant/staff mapping uses only its own encrypted credentials and calendar.
- Evidence: sanitized OAuth test result, event IDs, callback result and tenant-isolation test record.

## 7. Application security and privacy

- [ ] Confirm platform admin allowlist contains only verified intended accounts.
- [ ] Verify tenant isolation for business admin and staff roles; include cross-tenant foreign-ID read/update/delete attempts.
- [ ] Verify CSRF/session protections, rate limits, security headers and webhook signature validation.
- [ ] Confirm audit logs do not store access tokens, message bodies unnecessarily, secrets, or full provider payloads.
- [ ] Confirm retention/deletion expectations for messages, customer data, audit events and backups.
- [ ] Review dependency audit findings and document accepted exceptions with expiry dates.
- [ ] Complete a focused penetration/abuse review of authentication, tenant switching, webhooks, booking races, exports and admin endpoints.
- Evidence: test run URLs, security review record, and approved exception register.

## 8. Performance, reliability and accessibility

- [ ] Define expected tenant count, concurrent users, inbound message rate, booking rate and response-time targets.
- [ ] Run load tests against staging with production-like PostgreSQL and cron/worker limits.
- [ ] Measure API latency, queue age, scheduler duration, database connections, memory, CPU and frontend page performance.
- [ ] Test rate-limit behavior and provider throttling/backoff.
- [ ] Test mobile layouts, keyboard navigation, form validation, empty/error/loading states and major business workflows in supported browsers.
- [ ] Confirm alerting/monitoring exists for 5xx spikes, failed jobs, stale queue backlog, webhook failures, Meta/Google auth failures, storage exhaustion and backup failures.
- Evidence: test plan, results, target-vs-actual metrics, and resolved issue list.

## 9. Backup, restore and rollback

- [ ] Confirm automated Neon backups/PITR retention and document who can restore them.
- [ ] Perform a restore drill into an isolated database; verify migrations and representative tenant records.
- [ ] Verify uploaded files/storage backups separately if the application stores user files.
- [ ] Record the known-good application commit and deployment artifact.
- [ ] Rehearse rollback of application code and a backward-compatible migration strategy; do not assume destructive migrations can be reversed safely.
- [ ] Define incident owner, escalation channel, customer communication and data-loss decision process.
- Evidence: backup/snapshot ID, restore timestamp, validation queries, rollback rehearsal and named owner.

## 10. Controlled release and post-release checks

- [ ] Owner explicitly approves production deployment and the chosen maintenance window.
- [ ] Announce the change and pause onboarding if the release requires it.
- [ ] Deploy the exact reviewed commit; do not deploy an unreviewed working tree.
- [ ] Run migrations only after backup and approval.
- [ ] Run smoke checks for login, workspace switching, business settings, inbox, WhatsApp webhook, outbound test, booking, Calendar, queue and admin dashboard.
- [ ] Monitor logs, queue, provider delivery callbacks, database connections and error rates during the agreed observation window.
- [ ] Confirm backup completion after release and record final release SHA.
- [ ] If any critical check fails, stop onboarding and follow the rollback/incident plan.

## Current known state (2026-11-25)

- Repository-level CI and SQLite/PostgreSQL 16 migration rollback/replay verification are implemented; latest merged main commit `b73d3c1da70834d33c4b7f7546cec68d77dc20d6` passed post-merge CI [run 45540477079](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45540477079).
- Cross-tenant authorization and foreign-ID mutation regression coverage has passed CI.
- Production readiness endpoint and strict configuration preflight command exist.
- AI supports a strict read-only business-tool allowlist including appointment availability; outbound reply race guards are regression-tested. Groq telemetry excludes prompts/completions and a shared cooldown is activated after HTTP 429 responses.
- Calendar OAuth errors and calendar-list loading failures are surfaced safely in the settings UI. Staff notification preferences cover database notifications and configurable email channels.
- Actual Neon connectivity/TLS, Hostinger plan/runtime/cron, live Meta and Google acceptance, production-like load/abuse testing, browser profiling, backup/restore drill and production deployment have **not** been verified by this checklist.
- No production deployment is authorized by this document.
