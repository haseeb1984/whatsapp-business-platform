# WhatsApp Business Platform

A reusable multi-tenant WhatsApp Business automation platform using the official Meta WhatsApp Business Cloud API.

## Current implementation status

**Foundation and tenancy:** Laravel 13 / React 19 / TypeScript / Inertia application, registration/login, password recovery, email verification, multi-workspace switching, tenant context, role authorization and fail-closed tenant-owned model scoping are implemented. The fail-closed tenant concern covers all 22 tenant-owned models currently present; tenant-aware composite foreign keys, bulk-write protection and background-job tenant-context lifecycle checks are implemented. The route-level negative authorization matrix is still being expanded for uncovered routes.

**Business configuration:** Tenant-scoped business profile, timezone/locale, weekly hours, holidays/special hours, services/pricing, staff and assignments, staff schedules/exceptions, booking policies and FAQ management are implemented.

**WhatsApp and automation:** Official Meta Cloud API connection setup, encrypted credentials, phone/WABA verification, signed and idempotent webhooks, retryable event processing, encrypted messages, delivery-status correlation, policy-aware text sending, approved template catalog synchronization and approved-template sending are implemented. Outbound text/template messages are persisted through a transactional outbox and delivered by queued workers after the creation transaction commits; duplicate-job claiming, pending recovery, stale-send reconciliation and explicit unknown outcomes are covered by tests. The Groq FAQ assistant uses published FAQ evidence, deterministic business facts and safe human handover; unsupported questions are not answered with invented facts.

**Inbox and booking:** Tenant-scoped inbox, staff assignment/takeover, manual replies, internal notes and automation pause/resume are implemented. Booking includes timezone-aware availability, staff/service eligibility, temporary holds, Google FreeBusy, calendar-backed confirmation/cancellation, WhatsApp booking conversations, reminder scheduling, approved-template reminders outside the 24-hour window, delivery-state tracking, rescheduling and recovery for ambiguous Calendar outcomes.

**Google Calendar and dashboards:** OAuth with state/PKCE, encrypted token storage, refresh/revocation, writable calendar selection, per-staff mappings, FreeBusy and event reconciliation are implemented. Business and platform-admin dashboards, tenant usage summaries, audit history, tenant search and CSV export are implemented.

**Production acceptance still pending:** Actual Neon connection/TLS and migration rehearsal, Hostinger plan/runtime and cron validation, live Meta and Google account validation, penetration/abuse and load testing, browser performance profiling, deployment rehearsal, backup/restore drill and Meta production onboarding. Composer and npm lockfiles are committed, and CI uses reproducible locked installs; this does not replace live infrastructure validation.

**Current CI:** The main CI workflow validates Composer, runs the SQLite and PostgreSQL 16 feature suites (including the true two-process PostgreSQL webhook idempotency test), verifies full migration rollback/replay on both databases, audits PHP and frontend production dependencies, installs frontend dependencies with lockfile-exact `npm ci`, builds the Vite frontend, and checks lazy-loaded Inertia page chunks. The latest queue-recovery test batch [PR #17](https://github.com/haseeb1984/whatsapp-business-platform/pull/17) passed its full CI run [45297353400](https://github.com/actions/runs/45297353400) and was merged as `f68c92973115ac6ac1b7aec941210557654f6f4b`. Follow-up [PR #18](https://github.com/haseeb1984/whatsapp-business-platform/pull/18) switched frontend CI to lockfile-exact `npm ci`; its full CI [45298374901](https://github.com/actions/runs/45298374901) passed, and it was merged as `af4a81b589328ff1b5a3363836fc8bed3fb7fe94`. Main and work were synchronized after the merge; the tenant-role authorization matrix is the next verification batch.

## Approved stack

- Backend: Laravel 13, PHP 8.3+
- Frontend: React 19, TypeScript, Inertia.js, Vite and Tailwind CSS
- Database: Neon PostgreSQL
- Queue: Laravel database queue, processed in bounded cron-triggered batches on Hostinger shared hosting
- AI: Groq through a provider-independent adapter (grounded FAQ selection and queued inbound reply orchestration implemented)
- Messaging: Official Meta WhatsApp Cloud API only
- Calendar: Google Calendar API (OAuth, FreeBusy, event create/delete and booking confirmation/cancellation orchestration implemented)
- Hosting: Hostinger Business
- Vercel: explicitly excluded

Redis, Docker, WebSockets and always-running queue workers are not assumed to be available on Hostinger Business shared hosting.

## Product goal

One application and one codebase serve multiple independent businesses. Each tenant configures its own WhatsApp connection, business profile, FAQs, services, staff, calendars, hours, policies and users. Onboarding is configuration-driven; separate bot codebases or deployments are not required.

## Documentation

| Document | Purpose |
|---|---|
| [Requirements](docs/REQUIREMENTS.md) | Product scope and acceptance requirements |
| [Architecture](docs/ARCHITECTURE.md) | Components, deployment topology and runtime constraints |
| [Database](docs/DATABASE.md) | Tenant model, entities, constraints and indexes |
| [WhatsApp](docs/WHATSAPP.md) | Meta webhooks, signatures, idempotency and messaging |
| [AI](docs/AI.md) | Groq abstraction, grounded FAQ and safe tool execution |
| [Booking and Calendar](docs/BOOKING-CALENDAR.md) | Availability, appointment lifecycle and reconciliation |
| [Security](docs/SECURITY.md) | Tenant isolation, secrets and privacy |
| [Deployment](docs/DEPLOYMENT.md) | Hostinger + Neon deployment |
| [Roadmap](docs/ROADMAP.md) | Milestones and acceptance gates |

## Local development setup

Prerequisites: PHP 8.3+, Composer, Node.js 22+, npm, and a PostgreSQL database. The app can use SQLite for isolated tests, but Neon PostgreSQL is the intended application database.

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Create the local environment file and key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Configure `DB_URL` with your Neon connection string and keep `DB_SSLMODE=require`. Never commit the connection string.

4. Install and build frontend dependencies:

   ```bash
   npm ci
   npm run build
   ```

5. Run database migrations:

   ```bash
   php artisan migrate
   ```

6. Start the local development server:

   ```bash
   php artisan serve
   ```

Open `http://127.0.0.1:8000`. New users can register an account and create an initial business workspace; existing users can sign in. The application includes tenant-scoped business configuration, inbox, booking, Calendar integration and business/platform operations dashboards. Business profile, weekly hours, holidays, services, staff, FAQs, WhatsApp, conversations, appointments and Google Calendar settings are available from its links.

## Verification

GitHub Actions is the source of truth for the full verification pipeline:

- Composer manifest validation and dependency installation
- PHP feature suites on SQLite and PostgreSQL 16
- Full migration rollback and replay on both database engines
- Composer and frontend production dependency audits
- Vite production build and lazy-loaded Inertia page-chunk manifest guard
- Scheduler regression coverage for minute cadence and overlap protection

The latest post-merge `main` verification is [run 45277647644](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45277647644), which passed after all feature PRs were merged. The PostgreSQL multi-worker webhook concurrency regression from [PR #5](https://github.com/haseeb1984/whatsapp-business-platform/pull/5) now runs in the main PostgreSQL 16 CI suite.

Run the checks locally with `composer validate`, `php artisan test`, and `npm run build`. The CI PostgreSQL service is a compatibility gate; it does not replace a connection and migration rehearsal against the actual Neon project.

Before a production deployment, configure the required environment variables and run `php artisan platform:preflight --strict`. This checks configuration presence and runtime assumptions without printing secrets; it does not validate live Meta/Google credentials or prove Neon connectivity.

## Development principles

1. Use official APIs only; never automate WhatsApp Web.
2. Enforce tenant isolation on every request, job, query and integration.
3. Never invent business facts, prices, policies, availability or booking confirmations.
4. Never confirm a booking until calendar synchronization and local persistence succeed.
5. Persist inbound webhook events before acknowledging them; deduplicate retries.
6. Human handover is a hard automation gate.
7. Keep integrations behind interfaces and make failures observable.
8. No paid services or plan upgrades without explicit approval.
9. Keep documentation aligned with implemented behavior; label planned work clearly.

## Hostinger shared-hosting constraints

The initial runtime uses short HTTP requests and bounded database-queue processing invoked by cron. Cron-based processing introduces response latency and has finite throughput; it is not equivalent to a persistent worker. Load and latency testing will determine whether the Hostinger plan can meet the agreed operating requirements. Any VPS or paid infrastructure change requires approval.

The actual Hostinger plan limits, PHP extensions, cron frequency, outbound connectivity, and Neon account limits must be verified before production deployment.
