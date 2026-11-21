# WhatsApp Business Platform

A reusable multi-tenant WhatsApp Business automation platform using the official Meta WhatsApp Business Cloud API.

## Current implementation status

**Milestone 0:** Product requirements, architecture, database, security and deployment specifications committed. Actual Hostinger/Neon account limits still need verification.

**Milestone 1:** Laravel 13 / React 19 / TypeScript / Inertia application shell, CI dependency installation, SQLite feature-test setup and production frontend build are working. A disposable Neon/PostgreSQL migration rehearsal remains pending.

**Milestone 2:** Registration, login/logout, password recovery, signed email verification, multi-workspace selection/switching, tenant context, role middleware, suspended-tenant protection and fail-closed tenant-owned Eloquent model scoping are implemented. Broader tenant-aware foreign-key coverage and end-to-end queued-job isolation remain hardening tasks.

**Milestone 3:** Business profile, timezone/locale, weekly hours, holidays/special hours, services/pricing, staff/service assignments, staff weekly hours and date-specific exceptions, booking policies, and FAQ CRUD/publication/search are implemented with tenant scoping and admin controls. Full business dashboards and remaining UX polish are still in progress.

**Milestone 4:** Official Meta WhatsApp Cloud API connection setup, encrypted credentials, phone/WABA verification, signed/idempotent webhook ingestion, retryable event processing, encrypted message persistence, delivery-status handling and policy-aware text sending are implemented. Approved template lifecycle and Embedded Signup remain pending.

**Milestone 5:** Tenant-scoped inbox, staff assignment/takeover, manual replies, encrypted internal notes, automation pause/resume and close/reopen controls are implemented. Staff notifications/invitations, real-time updates and expanded audit history remain pending.

**Milestone 6:** Provider-independent Groq integration, published-FAQ grounding, approved-answer-only output, queued inbound reply workflow, rate-limit fallback, prompt-injection checks and safe human handover are implemented. Broader intent routing, structured business-fact retrieval and allowlisted booking tools remain pending.

**Milestone 7:** Tenant-timezone availability, staff/service eligibility, business hours/holidays, staff schedules/date exceptions, booking policies, local and Google FreeBusy conflict checks, idempotent temporary holds, bounded expiry, and the appointment dashboard are implemented. Calendar-backed confirmation/cancellation, idempotent 24h/2h reminder scheduling, bounded cron dispatch and delivery-state tracking are implemented. Reminder text is sent only inside the 24-hour window and while automation is active; outside-window reminders are blocked pending approved template support. WhatsApp booking conversation and rescheduling remain pending.

**Milestone 8:** Google OAuth with state + PKCE validation, encrypted access/refresh tokens, refresh/revocation, writable calendar listing, tenant default and per-staff calendar mapping, FreeBusy, deterministic event creation/reconciliation lookup and event deletion are implemented. Live Google test-account validation and event update/reschedule remain pending.

**Current CI:** GitHub Actions run [44951430610](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/44951430610) passed on commit `c898ab96b0f36f6e8a73a600b917ee346b4aa0f1`: **131 Laravel tests (481 assertions)**, Composer manifest validation/dependency installation and Vite production build. Tests currently use SQLite; Neon/PostgreSQL migration and constraint rehearsal remains outstanding.

**Not production-ready yet:** WhatsApp approved templates (including out-of-window reminders), end-to-end WhatsApp booking and rescheduling, full platform/business administration dashboards, expanded tenant isolation and operational hardening, Neon rehearsal, Hostinger deployment validation, live Meta/Google acceptance tests, backup/restore and production onboarding.

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
   npm install
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

Open `http://127.0.0.1:8000`. New users can register an account and create an initial business workspace; existing users can sign in. The dashboard is still a foundation screen, not a completed business dashboard. Business profile, weekly hours, holidays, services, staff, FAQs, WhatsApp, conversations, appointments and Google Calendar settings are available from its links.

## Verification

- `composer validate`
- `php artisan test`
- `npm run build`

GitHub Actions run [44947637029](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/44947637029) passed on commit `230e365adc4be1a655d26db20205c255d4a63fa7`: Composer manifest validation, dependency installation, **111 Laravel tests (410 assertions)**, npm dependency installation and Vite production build. The tests use SQLite; a separate Neon PostgreSQL migration/constraint rehearsal is still required.

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
