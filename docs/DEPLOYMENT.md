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

The repository contains a Laravel 13 / React / TypeScript / Inertia application with tenant-aware authentication and business configuration, official Meta WhatsApp Cloud API connection and webhook processing, encrypted message storage, outbound text and approved-template sending, a Groq-grounded FAQ assistant, queued inbound replies, a staff inbox/handover workflow, and a timezone-aware booking engine. Booking includes service/staff availability, temporary holds, Google FreeBusy checks, calendar-backed confirmation/cancellation, WhatsApp booking conversations, reminder scheduling and delivery-state tracking, approved-template reminders outside the 24-hour window, rescheduling, and reconciliation for ambiguous Google Calendar outcomes. Google OAuth uses PKCE and encrypted token storage, supports writable calendar selection and per-staff mappings, and includes token refresh/revocation. Business and platform dashboards, tenant search/pagination/CSV export, per-tenant usage metrics and append-only admin/staff mutation audit history are implemented. Production deployment is not complete: penetration/abuse testing, live Meta and Google account acceptance, cron load/latency measurement, Hostinger + Neon deployment rehearsal, backup/restore drill and Meta production onboarding remain outstanding. CI runs the full suite against SQLite and PostgreSQL 16. Latest verified full CI run [45057295773](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45057295773) passed on commit `c62374a052c3ef9f3db8f87b26c5d12fcb468614`: 182 tests / 762 assertions on each database (SQLite and PostgreSQL 16), clean Composer and npm production dependency audits, and the Vite production build. This validates PostgreSQL compatibility in CI but is not a substitute for a final migration/connection rehearsal against the actual Neon project.

Laravel 13 requires PHP 8.3 or higher. Hostinger's current PHP selector documentation lists PHP 8.2 and newer options, including 8.3–8.5. Select PHP 8.3+ for this application and verify required extensions in the actual site's PHP Info page. Do not assume the account's current PHP setting.

## 3. Important Hostinger shared-hosting constraints

Do not assume the Business plan supports persistent queue workers, Redis, Docker, WebSockets, or always-on background processes. The implementation uses short HTTP requests and bounded cron-invoked processing commands.

Cron-driven processing can introduce noticeable message response latency. It must be measured against agreed service expectations. If cron frequency, process limits, request timeouts, or resource limits are insufficient, propose a VPS/runtime change with cost and migration impact; do not silently add another provider.

## 4. Verify before deployment

The public, rate-limited `GET /health/ready` endpoint checks database connectivity and (when using Laravel's database queue) the queue storage table. It does not prove that cron workers are running or that Meta/Google credentials are valid; those require separate operational checks.


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
cd /home/ACCOUNT/domains/DOMAIN/public_html/APP_ROOT && php artisan queue:work database --stop-when-empty --max-time=20 --tries=3 --sleep=1
```

Replace the path with the real application path. The command must run outside the public directory. Confirm PHP CLI path, cron cadence, process limits, and logs in hPanel. Do not configure this cron until the application is deployed and queue migrations have succeeded.

Configure Laravel's scheduler with a separate cron entry (after confirming the real application path and PHP CLI binary):

```bash
* * * * * cd /home/ACCOUNT/domains/DOMAIN/public_html/APP_ROOT && php artisan schedule:run >> /dev/null 2>&1
```

This runs the scheduled `appointments:expire-holds` and `appointments:dispatch-reminders` tasks. The queue worker and scheduler are separate concerns; both need monitoring and bounded execution. Reminder jobs use free-form text only for other in-window workflows; appointment reminders use the configured approved Meta template even outside the 24-hour customer-service window. Set `META_APPOINTMENT_REMINDER_TEMPLATE` and `META_APPOINTMENT_REMINDER_LANGUAGE` to the exact synced approved template. The current reminder mapper expects exactly two sequential BODY variables: `{{1}}` = service name and `{{2}}` = appointment date/time in the business timezone. If the approved template is unavailable or does not match, the reminder is safely held rather than sending an unapproved/free-form message. The 20-second limit is a starting value to validate, not a guaranteed Hostinger allowance.

## 9. Rollback and recovery

- Back up database before schema changes.
- Use backward-compatible migrations where possible.
- Keep a known-good deployment artifact or Git commit.
- Do not roll back migrations destructively without a tested data plan.
- If queue processing fails, leave durable events/jobs persisted and pause retries safely.
- If Meta/Google credentials are revoked, mark integration unhealthy and notify tenant admins without leaking credentials.
