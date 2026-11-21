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

The repository contains a Laravel 13 application shell with React, TypeScript, Inertia, Vite, PostgreSQL configuration, an initial users/queue/tenant migration set, and health endpoints. It is not yet a complete SaaS product. Meta, Groq, Google Calendar, business workflows, authentication UI, and full tenant authorization are later milestones.

Laravel 13 requires PHP 8.3 or higher. Hostinger's current PHP selector documentation lists PHP 8.2 and newer options, including 8.3–8.5. Select PHP 8.3+ for this application and verify required extensions in the actual site's PHP Info page. Do not assume the account's current PHP setting.

## 3. Important Hostinger shared-hosting constraints

Do not assume the Business plan supports persistent queue workers, Redis, Docker, WebSockets, or always-on background processes. The implementation uses short HTTP requests and bounded cron-invoked processing commands.

Cron-driven processing can introduce noticeable message response latency. It must be measured against agreed service expectations. If cron frequency, process limits, request timeouts, or resource limits are insufficient, propose a VPS/runtime change with cost and migration impact; do not silently add another provider.

## 4. Verify before deployment

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

The actual Hostinger account has not been inspected through a connected account tool; these checks remain outstanding.

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
- APP_ENV, APP_KEY, APP_DEBUG=false, APP_URL
- DB_CONNECTION=pgsql, DB_URL, DB_SSLMODE=require
- QUEUE_CONNECTION=database, SESSION_DRIVER=file, CACHE_STORE=file
- META_APP_ID, META_APP_SECRET, META_WEBHOOK_VERIFY_TOKEN, META_GRAPH_API_VERSION
- GROQ_API_KEY, GROQ_MODEL
- GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI

Never commit real values. Keep `.env` outside the public web root and ensure the public document root points to Laravel's `public/` directory.

## 7. Build and deployment sequence

1. Confirm plan limits and PHP/Node build tooling.
2. Provision Neon environment and credentials.
3. Configure domain and HTTPS on Hostinger.
4. Deploy the repository and install PHP dependencies with `composer install --no-dev --optimize-autoloader`.
5. Build frontend assets in a compatible build environment using `npm ci` (after a lockfile is committed) or `npm install`, then `npm run build`.
6. Set production environment variables securely.
7. Run `php artisan migrate --force` after a database backup.
8. Run `php artisan optimize` only after verifying the environment configuration.
9. Configure Meta webhook URL and verify token; subscribe required fields.
10. Configure Google OAuth redirect URI and consent configuration.
11. Run end-to-end tests with Meta test assets and a test calendar.
12. Verify logs, queue backlog, duplicate-event behavior, backup, and restore.
13. Enable real business onboarding only after acceptance checks pass.

## 8. Cron-backed queue processing

The initial shared-hosting strategy is a database queue, not a persistent daemon. After queue jobs are introduced and the worker command has been tested, configure a Hostinger cron job to run a bounded worker invocation at the supported cadence. Proposed command:

```bash
cd /home/ACCOUNT/domains/DOMAIN/public_html/APP_ROOT && php artisan queue:work database --stop-when-empty --max-time=20 --tries=3 --sleep=1
```

Replace the path with the real application path. The command must run outside the public directory. Confirm PHP CLI path, cron cadence, process limits, and logs in hPanel. Do not configure this cron until the application is deployed and queue migrations have succeeded. The 20-second limit is a starting value to validate, not a guaranteed Hostinger allowance.

## 9. Rollback and recovery

- Back up database before schema changes.
- Use backward-compatible migrations where possible.
- Keep a known-good deployment artifact or Git commit.
- Do not roll back migrations destructively without a tested data plan.
- If queue processing fails, leave durable events/jobs persisted and pause retries safely.
- If Meta/Google credentials are revoked, mark integration unhealthy and notify tenant admins without leaking credentials.
