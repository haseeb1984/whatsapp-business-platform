# WhatsApp Business Platform

A reusable multi-tenant WhatsApp Business automation platform using the official Meta WhatsApp Business Cloud API.

## Current implementation status

**Milestone 0:** Technical specification committed.  
**Milestone 1:** Laravel 13 / React / Inertia application shell and initial tenant schema committed; Composer dependencies install successfully in CI and the frontend production build passes.  
**Milestone 2 (in progress):** Registration, login/logout, password reset, signed email verification, workspace selection/switching, tenant context and role-gating foundations are committed. The current CI suite passes; additional production model coverage, authorization hardening and database isolation acceptance work remain. A fail-closed tenant-owned model concern (including bulk Eloquent tenant-ID update protection) and explicit background-work tenant context helper are now committed. Request middleware also clears/restores tenant context after each request, and suspended tenants are excluded from workspace selection. All currently implemented tenant-owned models opt in; every future tenant-owned model must also adopt it. Composite database constraints still need broader coverage across future cross-entity relationships.
**Milestone 3 (in progress):** Tenant-scoped business profile CRUD, regional settings, weekly business hours editor, holiday/special-hours CRUD, service/pricing management, staff CRUD with service assignments, staff weekly working hours, and FAQ CRUD with draft/published/archived lifecycle and tenant-scoped search are committed. The CI suite passes with coverage for these modules. Staff date-specific exceptions, business policies and complete dashboards remain outstanding.
**Partially implemented:** Tenant WhatsApp connection setup with encrypted access tokens, Meta Graph API verification of phone number and WABA membership, signed webhook verification, durable encrypted/idempotent event ingestion, active phone-number routing, a retryable processor that normalizes inbound messages into encrypted customer/conversation/message records, and a text sender that enforces the 24-hour customer-service window and automation/human state gate. **Partially implemented:** a provider-independent Groq adapter and tenant-scoped grounded FAQ selector; the model selects approved FAQ IDs and the backend returns only stored approved answers. This service is not yet connected to the live inbound conversation processor. **Not implemented yet:** approved template sending, complete human handover workflows, intent orchestration, appointment booking, Google Calendar OAuth, and production business/platform dashboards.

## Approved stack

- Backend: Laravel 13, PHP 8.3+
- Frontend: React 19, TypeScript, Inertia.js, Vite and Tailwind CSS
- Database: Neon PostgreSQL
- Queue: Laravel database queue, processed in bounded cron-triggered batches on Hostinger shared hosting
- AI: Groq through a provider-independent adapter (grounded FAQ selection implemented; inbound orchestration pending)
- Messaging: Official Meta WhatsApp Cloud API only
- Calendar: Google Calendar API (planned)
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

Open `http://127.0.0.1:8000`. New users can register an account and create an initial business workspace; existing users can sign in. The dashboard is still a foundation screen, not a completed business dashboard. Business profile, weekly hours, holidays, services, staff and FAQs can be configured from its links.

## Verification

- `composer validate`
- `php artisan test`
- `npm run build`

GitHub Actions run [44930789071](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/44930789071) passed on commit `67c9b4f9c0ce1d2b97c7f2e53b781fcefe48b3e4`: Composer manifest validation, dependency installation, **81 Laravel tests (295 assertions)**, npm dependency installation and Vite production build. The tests use SQLite; a separate Neon PostgreSQL migration/constraint rehearsal is still required.

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
