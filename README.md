# WhatsApp Business Platform

A reusable multi-tenant WhatsApp Business automation platform using the official Meta WhatsApp Business Cloud API.

## Current implementation status

**Foundation and tenancy:** Laravel 13 / React 19 / TypeScript / Inertia application, registration/login, password recovery, email verification, multi-workspace switching, tenant context, role authorization and fail-closed tenant-owned model scoping are implemented. The fail-closed tenant concern covers all 22 tenant-owned models currently present; tenant-aware composite foreign keys, bulk-write protection and background-job tenant-context lifecycle checks are implemented. The staff-role denial matrix now covers the major business configuration, booking, inbox, WhatsApp and Calendar route groups; future routes must add equivalent negative tests.

**Business configuration:** Tenant-scoped business profile, timezone/locale, weekly hours, holidays/special hours, services/pricing, staff and assignments, staff schedules/exceptions, booking policies and FAQ management are implemented.

**WhatsApp and automation:** Official Meta Cloud API connection setup, encrypted credentials, phone/WABA verification, signed and idempotent webhooks, retryable event processing, encrypted messages, delivery-status correlation, policy-aware text sending, approved template catalog synchronization and approved-template sending are implemented. Outbound text/template messages are persisted through a transactional outbox and delivered by queued workers after the creation transaction commits; duplicate-job claiming, pending recovery, stale-send reconciliation and explicit unknown outcomes are covered by tests. The Groq FAQ assistant uses published FAQ evidence, deterministic business facts and safe human handover; unsupported questions are not answered with invented facts.

**Inbox and booking:** Tenant-scoped inbox, staff assignment/takeover, manual replies, internal notes and automation pause/resume are implemented. Booking includes timezone-aware availability, staff/service eligibility, temporary holds, Google FreeBusy, calendar-backed confirmation/cancellation, WhatsApp booking conversations, reminder scheduling, approved-template reminders outside the 24-hour window, delivery-state tracking, rescheduling and recovery for ambiguous Calendar outcomes.

**Google Calendar and dashboards:** OAuth with state/PKCE, encrypted token storage, refresh/revocation, writable calendar selection, per-staff mappings, FreeBusy and event reconciliation are implemented. Business and platform-admin dashboards, tenant usage summaries, audit history, tenant search and CSV export are implemented.

**Production acceptance still pending:** Actual Neon connection/TLS and migration rehearsal, Hostinger plan/runtime and cron validation, live Meta and Google account validation, penetration/abuse and load testing, browser performance profiling, deployment rehearsal, backup/restore drill and Meta production onboarding. Composer and npm lockfiles are committed, and CI uses reproducible locked installs; this does not replace live infrastructure validation.

**Current CI:** The latest verified merge is PR #60, commit `aedfa2aab934649c86d9db96c45e77cdd1dd5aac`. Post-merge GitHub Actions [run 45754748966](https://github.com/actions/runs/45754748966) passed the full pipeline: SQLite and PostgreSQL 16 feature suites, migration rollback/replay on both databases, Composer and frontend production dependency audits, lockfile-exact frontend install, Vite production build and route-level page-chunk verification. Recent PostgreSQL concurrency coverage includes two independent workers competing for one outbox dispatch lease (PR #56), two customers competing for one appointment slot (PR #58), and distinct first-contact webhook events for the same new sender (PR #59). PR #60 also aligned the README, roadmap and go-live evidence checkpoint. These are CI regressions, not live-provider or production-load evidence.

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
| [Go-live checklist](docs/GO-LIVE-CHECKLIST.md) | Evidence-based production acceptance, cutover, rollback and handover checklist |

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

The latest full CI verification is [run 45754748966](https://github.com/actions/runs/45754748966) for PR #60 merge commit `aedfa2a`. It passed all pipeline steps, including SQLite and PostgreSQL 16 tests, migration rollback/replay, dependency audits, frontend build and route-level page-chunk verification. The PostgreSQL multi-worker concurrency regressions cover outbox dispatch (PR #56), appointment-slot contention (PR #58), and distinct first-contact webhook events for the same sender (PR #59).

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
