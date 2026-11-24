# Deployment Plan: Hostinger Business + Neon

## 1. Approved deployment topology

- **Hostinger Business:** Laravel/PHP application, public webhook/API routes, dashboard routes, and compiled React assets.
- **Neon PostgreSQL:** managed primary database accessed over TLS.
- **Meta Cloud API:** inbound/outbound WhatsApp messaging.
- **Groq:** initial AI provider.
- **Google Calendar API:** appointment availability and event synchronization.
- **Hostinger cron:** bounded database-queue, reminder, and reconciliation processing.
- **Vercel:** not used.

No additional paid hosting, database tier, queue service, or AI provider may be purchased without explicit approval.

## 2. Current application runtime

The repository contains a Laravel 13 / React / TypeScript / Inertia application with tenant-aware authentication and business configuration, official Meta WhatsApp Cloud API connection and webhook processing, encrypted message storage, a durable outbound message-row outbox, queued Meta delivery, a Groq-grounded FAQ assistant, a staff inbox/handover workflow, and a timezone-aware booking engine. Outbound text and approved-template messages commit to the database before the queue worker calls Meta; delivery states include pending, sending, sent, failed and unknown. Pending outbox rows are recovered by the scheduler, stale in-flight sends become unknown rather than being resent, and appointment reminders are finalized by the delivery worker. The platform console reports pending/sending/unknown/failed outbound counts without exposing message content. Booking includes service/staff availability, temporary holds, Google FreeBusy checks, calendar-backed confirmation/cancellation, WhatsApp booking conversations, reminder scheduling, approved-template reminders outside the 24-hour window, rescheduling, and reconciliation for ambiguous Google Calendar outcomes. Google OAuth uses PKCE and encrypted token storage, supports writable calendar selection and per-staff mappings, and includes token refresh/revocation. Business and platform dashboards, tenant search/pagination/CSV export, per-tenant usage metrics and append-only audit history are implemented. The latest merged feature verification is [CI run 45240511706](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45240511706), which passed SQLite and PostgreSQL 16 feature suites, migration rollback/replay, Composer/npm production dependency audits, Vite production build, and the lazy-loaded Inertia page-chunk guard. The merged PR also adds regression coverage for webhook duplicate processing, late failure callbacks, duplicate Meta provider message IDs, ambiguous template-send outcomes, tenant-scoped inbox/booking/Calendar operations, same-user multi-workspace Google credential isolation, and spreadsheet-formula-safe tenant CSV export. This verifies PostgreSQL 16 compatibility in CI but is not a live Neon rehearsal. Production gates still include live Neon connection/TLS/migration validation, Hostinger runtime/cron rehearsal, browser performance profiling, load/abuse testing, live Meta/Google acceptance, backup/restore and Meta production onboarding.

Laravel 13 requires PHP 8.3 or higher. Hostinger's current PHP selector documentation lists PHP 8.2 and newer options, including 8.3–8.5. Select PHP 8.3+ for this application and verify required extensions in the actual site's PHP Info page. Do not assume the account's current PHP setting.

## 3. Important Hostinger shared-hosting constraints

Outbound WhatsApp sends now use a durable message-row outbox. The message creation transaction commits before `DeliverWhatsAppMessage` calls Meta; provider HTTP is outside conversation/appointment locks. The worker claims a pending row once, and ambiguous outcomes are marked `unknown` without automatic resend. The scheduler recovers lost pending-job dispatches and marks abandoned sending attempts unknown. This avoids holding database locks across provider network calls, but does not guarantee provider-side exactly-once delivery. Validate queue latency, worker timeout, lock contention and unknown-outcome operations in load tests before scaling production traffic.

Do not assume the Business plan supports persistent queue workers, Redis, Docker, WebSockets, or always-on background processes. The implementation uses short HTTP requests and bounded cron-invoked processing commands.

Cron-driven processing can introduce noticeable message response latency. It must be measured against agreed service expectations. If cron frequency, process limits, request timeouts, or resource limits are insufficient, propose a VPS/runtime change with cost and migration impact; do not silently add another provider.

## 4. Verify before deployment

Run the non-destructive runtime configuration preflight after setting the production environment variables:

```bash
php artisan platform:preflight --strict
```

It checks PHP 8.3+, PDO PostgreSQL/cURL/OpenSSL extensions, production/debug flags, HTTPS, PostgreSQL TLS, database queue after-commit and retry window, failed-job storage, persistent session/cache drivers, secure cookies, Meta app/webhook credentials, SMTP, Groq, Google OAuth and writable runtime directories. It does not print secret values. It does not connect to Meta or Google and does not prove that the configured Neon credentials work. Verify the database separately through the readiness endpoint and a controlled Neon migration rehearsal; run the preflight again after `config:cache` so the checks reflect the cached production configuration.


**Dependency reproducibility gate:** `composer.lock` and `package-lock.json` are committed on `main` through PR #6. CI validates the manifests and performs clean locked installs (`composer install` and `npm ci`), followed by Composer/npm production dependency audits and the Vite production build. Keep both lockfiles in sync with manifest changes; do not replace locked installs with floating dependency resolution.

The public, rate-limited `GET /health/ready` endpoint checks database connectivity and (when using Laravel's database queue) the queue storage table. It does not prove that cron workers are running or that Meta/Google credentials are valid; those require separate operational checks.

The platform operations console reports the database queue backlog count, age in minutes of the oldest queued job, total Laravel failed jobs, and failures in the last 24 hours. Recent failure rows expose only the failed-job ID, queue name, and timestamp; serialized payloads and exception traces are deliberately excluded from the dashboard. Use restricted server-side access and Laravel's `queue:failed` / `queue:retry <uuid>` commands only after inspecting the underlying failure and confirming that the job is safe to retry. A retry of an outbound delivery job must be treated cautiously: the message outbox state and provider outcome may be ambiguous, so do not blindly retry a job that could send a duplicate WhatsApp message.\n\nFailed Meta webhook events are retained with `status=failed` after their automatic retry budget is exhausted. After inspecting the event and correcting any underlying integration or data issue, an operator can replay one event with `php artisan webhooks:replay-failed <event-id>`. The command rejects non-failed events and events belonging to suspended or missing tenants; it never bulk-replays the dead-letter set. Monitor the platform console's failed queue count separately because webhook dead-letter rows and Laravel `failed_jobs` are distinct failure stores.


In Hostinger hPanel, verify the exact plan variant and current limits:
- PHP 8.3+ and required extensions (Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML, and PDO PostgreSQL);
- Composer availability or supported deployment workflow;
- Node.js version and build tooling (Vite 8 requires a supported modern Node.js runtime; Node 22 is the target for local/CI builds);
- document root and public directory configuration;
- SSH access and deployment options;
- cron frequency and execution time limits;
- process/entry-process limits, memory, CPU and inode limits;
- outbound HTTPS connectivity to Meta, Groq, Google, and Neon;
- SSL certificate and custom-domain routing;
- backup and restore options.

The actual Hostinger account has not been inspected through a connected account tool; these checks remain outstanding. For production, set `SESSION_SECURE_COOKIE=true`, keep `APP_DEBUG=false`, and never regenerate `APP_KEY` after encrypted integration credentials have been stored.

## 5. Neon configuration

- Use a separate Neon project/database for development and production.
- Use the pooled connection string where appropriate for shared-hosting connection churn; verify Laravel/PDO compatibility with the chosen endpoint.
- Set `DB_CONNECTION=pgsql`, `DB_URL` to the Neon connection URI, and `DB_SSLMODE=require`.
- Keep the connection string only in server environment variables; never commit it.
- Run migrations from a controlled deployment command.
- Monitor compute hours, storage, network transfer, active connections, and branch usage.
- Treat free-tier limits as development/early-test constraints, not a production capacity guarantee.
- Configure backup/restore expectations and test recovery.

## 6. Environment variables

The committed `.env.example` contains placeholder-only values. Configure real values in the hosting environment:
- APP_ENV=production, APP_KEY (stable and backed up), APP_DEBUG=false, APP_URL
- DB_CONNECTION=pgsql, DB_URL, DB_SSLMODE=require
- QUEUE_CONNECTION=database, SESSION_DRIVER=file, CACHE_STORE=file, SESSION_SECURE_COOKIE=true
- PLATFORM_ADMIN_EMAILS (comma-separated verified email addresses; only these accounts can access `/platform`)
- META_APP_ID, META_APP_SECRET, META_WEBHOOK_VERIFY_TOKEN, META_GRAPH_API_VERSION, META_APPOINTMENT_REMINDER_TEMPLATE, META_APPOINTMENT_REMINDER_LANGUAGE
- MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, MAIL_FROM_NAME (configure a working SMTP transport before enabling password recovery in production)
- GROQ_API_KEY, GROQ_MODEL
- GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI (must exactly match the registered Google OAuth redirect URI)

Never commit real values. Keep `.env` outside the public web root and ensure the public document root points to Laravel's `public/` directory.

## 7. Build and deployment sequence

1. Confirm plan limits and PHP/Node build tooling.
2. Provision Neon environment and credentials.
3. Configure domain and HTTPS on Hostinger.
4. Deploy the repository and install PHP dependencies with `composer install --no-dev --optimize-autoloader`.
5. Build frontend assets in a compatible build environment using `npm ci`, then `npm run build`.
6. Set production environment variables securely.
7. Run `php artisan migrate --force` after a database backup.
8. Run `php artisan optimize` only after verifying the environment configuration.
9. Configure Meta webhook URL and verify token; subscribe required fields.
10. Configure Google OAuth redirect URI and consent configuration.
11. Run end-to-end tests with Meta test assets and a test calendar.
12. Verify logs, queue backlog, duplicate-event behavior, backup, and restore.
13. Enable real business onboarding only after acceptance checks pass.

## 8. Cron-backed queue processing

The initial shared-hosting strategy is a database queue, not a persistent daemon. After queue jobs are introduced and the worker command has been tested, configure a Hostinger cron job to run a bounded worker invocation at the supported cadence. Laravel's scheduler expires temporary appointment holds and dispatches due appointment reminders once per minute. Proposed command:

```bash
cd /home/ACCOUNT/domains/DOMAIN/public_html/APP_ROOT && php artisan queue:work database --stop-when-empty --max-time=20 --tries=3 --sleep=1 --timeout=45
```

Replace the path with the real application path. The command must run outside the public directory. Confirm PHP CLI path, cron cadence, process limits, and logs in hPanel. Do not configure this cron until the application is deployed and queue migrations have succeeded.

Configure Laravel's scheduler with a separate cron entry (after confirming the real application path and PHP CLI binary):

```bash
* * * * * cd /home/ACCOUNT/domains/DOMAIN/public_html/APP_ROOT && php artisan schedule:run >> /dev/null 2>&1
```

This runs the scheduled `appointments:expire-holds`, `appointments:dispatch-reminders`, `messages:dispatch-pending`, and `messages:reconcile-stale-outbound` tasks. The queue worker and scheduler are separate concerns; both need monitoring and bounded execution. Reminder jobs use free-form text only for other in-window workflows; appointment reminders use the configured approved Meta template even outside the 24-hour customer-service window. Set `META_APPOINTMENT_REMINDER_TEMPLATE` and `META_APPOINTMENT_REMINDER_LANGUAGE` to the exact synced approved template. The current reminder mapper expects exactly two sequential BODY variables: `{{1}}` = service name and `{{2}}` = appointment date/time in the business timezone. If the approved template is unavailable or does not match, the reminder is safely held rather than sending an unapproved/free-form message. The 20-second limit is a starting value to validate, not a guaranteed Hostinger allowance.

### Outbound WhatsApp delivery outbox

Outbound text and approved-template messages are persisted in the tenant-scoped `messages` table with `delivery_status=pending` inside the application transaction. The transaction commits before `DeliverWhatsAppMessage` contacts Meta. The delivery job claims the row atomically as `sending`, runs once (`$tries=1`, job timeout 30 seconds), and records `sent`, `failed`, or `unknown`. A known provider rejection is failed; a connection/ambiguous provider outcome is unknown. Unknown messages are deliberately not resent automatically because Meta may already have accepted them.

The minute scheduler also recovers pending outbox rows older than five minutes if queue dispatch was interrupted, and changes `sending` rows older than ten minutes to `unknown` after a worker crash. Duplicate queue jobs are safe: only a row still in `pending` can be claimed for a provider request. Reminder delivery status is finalized by the same worker. This reduces transaction lock duration and protects against duplicate sends, but it does not provide provider-side exactly-once guarantees; unknown outcomes still require webhook evidence or operator reconciliation.

For the database queue, keep `retry_after` at 90 seconds and worker `--timeout` below that (45 seconds here); the WhatsApp delivery job itself has a 30-second timeout and one attempt. Verify Hostinger CLI/cron process limits before using these values in production.

## 9. Rollback and recovery

- Back up database before schema changes.
- Use backward-compatible migrations where possible.
- Keep a known-good deployment artifact or Git commit.
- Do not roll back migrations destructively without a tested data plan.
- If queue processing fails, leave durable events/jobs persisted and pause retries safely.
- If Meta/Google credentials are revoked, mark integration unhealthy and notify tenant admins without leaking credentials.

## Previous pre-deployment verification (superseded by the post-PR2 checkpoint below)

- Verified commit: `608ce382c79805f2068f4f52eed695ecf68b3c58`.
- [CI run 45104012689](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45104012689) passed 199 tests / 840 assertions on SQLite and PostgreSQL 16, full migration rollback/replay on both, Composer/npm production dependency audits, and the Vite production build.
- CI also verifies the route manifest contains 21 lazy-loaded Inertia page chunks, the message outbox dispatch/recovery flows, provider calls outside the message-creation transaction, duplicate-job protection, appointment reminder completion after delivery, and explicit inbox labels for queued/sending/unknown delivery states, and handover-race tests (queued staff replies survive release; queued automation replies are cancelled when staff takes over), global outbox health counts, cross-tenant isolation for outbound delivery jobs, and strict production runtime preflight checks, and a scheduler regression test that asserts all four recurring housekeeping commands remain registered every minute with overlap protection.
- This does not replace a live Neon connection/TLS/migration rehearsal or a Hostinger runtime/deployment rehearsal.
- Route-level lazy loading is implemented; the latest Vite build emits a 445.87 kB minified main JavaScript chunk (142.76 kB gzip), below the 500 kB advisory threshold. Browser-level performance profiling remains pending.
- The repository still needs committed `composer.lock` and `package-lock.json` plus locked install commands before reproducible deployment can be considered complete. CI action pins have been upgraded to Node 24-compatible releases.


## PostgreSQL multi-worker webhook concurrency verification (2026-11-23)

[CI run 45243247660](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45243247660) passed on the PostgreSQL 16 CI service and ran the true multi-worker webhook idempotency regression test from PR #5. Two independent PHP processes contend on the same webhook event; a test-only PostgreSQL trigger holds the first transaction to force overlap, and the test verifies one customer, one conversation and one inbound message. This is CI evidence only, not a live Neon rehearsal. PR #5 is merged into `main`; the test is part of the main PostgreSQL 16 CI suite. No production deployment has been performed.


## Dependency lockfile checkpoint (2026-11-23)

The manual workflow [45244631505](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45244631505) generated both lockfiles and passed validation, audits, clean installs and the frontend build. Its automatic PR creation step was blocked by repository Actions permissions, but the branch push succeeded. [PR #6](https://github.com/haseeb1984/whatsapp-business-platform/pull/6) was opened separately and its regular CI passed in [run 45245038911](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45245038911). Both lockfiles were merged to `main` in PR #6; the main CI now validates locked installs.
