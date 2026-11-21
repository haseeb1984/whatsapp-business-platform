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

## 2. Important Hostinger shared-hosting constraints

Do not assume the Business plan supports persistent queue workers, Redis, Docker, WebSockets, or always-on background processes. The implementation must use short HTTP requests and bounded cron-invoked processing commands until actual plan capabilities are verified.

Cron-driven processing can introduce noticeable message response latency. It must be measured against agreed service expectations. If cron frequency, process limits, request timeouts, or resource limits are insufficient, propose a VPS/runtime change with cost and migration impact; do not silently add another provider.

## 3. Verify before deployment

In Hostinger hPanel, verify the exact plan variant and current limits:
- PHP version and required extensions;
- Composer availability or supported deployment workflow;
- document root and public directory configuration;
- SSH access and deployment options;
- cron frequency and execution time limits;
- process/entry-process limits, memory, CPU and inode limits;
- outbound HTTPS connectivity to Meta, Groq, Google, and Neon;
- SSL certificate and custom-domain routing;
- backup and restore options.

The actual account has not been inspected through a connected Hostinger account tool; these checks remain outstanding.

## 4. Neon configuration

- Create a dedicated Neon project/database for each environment (development and production).
- Use the pooled connection string where appropriate for serverless/shared hosting connection churn; verify Laravel/PDO compatibility with the selected Neon endpoint.
- Require TLS and keep the connection string only in server environment variables.
- Run migrations from a controlled deployment command.
- Monitor compute hours, storage, network transfer, active connections, and branch usage.
- Treat free-tier limits as development/early-test constraints, not a production capacity guarantee.
- Configure backup/restore expectations and test recovery.

## 5. Environment variables

Maintain an `.env.example` with placeholders only. Expected categories include:

- APP_ENV, APP_KEY, APP_DEBUG, APP_URL
- LOG_CHANNEL, LOG_LEVEL
- DB_CONNECTION=pgsql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_SSLMODE
- QUEUE_CONNECTION=database
- SESSION_DRIVER and CACHE_STORE using supported database/file options
- META_APP_ID, META_APP_SECRET, META_WEBHOOK_VERIFY_TOKEN, META_GRAPH_API_VERSION
- GROQ_API_KEY, GROQ_MODEL, AI_TIMEOUT_SECONDS
- GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI
- CRON/worker batch limits and application-specific scheduling configuration

Never commit real values. Exact variable names will be finalized during implementation and kept consistent with code.

## 6. Deployment sequence

1. Confirm plan limits and PHP/Node build tooling.
2. Provision Neon environment and credentials.
3. Configure domain and HTTPS on Hostinger.
4. Deploy Laravel application and build frontend assets.
5. Set production environment variables securely.
6. Run migrations and minimal health checks.
7. Configure cron for bounded queue processing, reminders, cleanup, and reconciliation.
8. Configure Meta webhook URL and verify token; subscribe required fields.
9. Configure Google OAuth redirect URI and consent configuration.
10. Run end-to-end test with Meta test number and test calendar.
11. Verify logs, queue backlog, duplicate-event behavior, backup, and restore.
12. Enable real business onboarding only after acceptance checks pass.

## 7. Rollback and recovery

- Back up database before schema changes.
- Use backward-compatible migrations where possible.
- Keep a known-good deployment artifact or Git commit.
- Do not roll back migrations destructively without a tested data plan.
- If queue processing fails, leave durable events/jobs persisted and pause retries safely.
- If Meta/Google credentials are revoked, mark integration unhealthy and notify tenant admins without leaking credentials.
