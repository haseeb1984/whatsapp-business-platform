# WhatsApp Business Platform

A reusable multi-tenant WhatsApp Business automation platform using the official Meta WhatsApp Business Cloud API.

## Current implementation status

**Milestone 0:** Technical specification committed.  
**Milestone 1:** Laravel 13 / React / Inertia application shell and initial tenant schema committed; dependency installation and runtime tests are still pending.  
**Not implemented yet:** Meta integration, AI workflows, human handover, appointment booking, Google Calendar OAuth, production authentication screens, and business dashboards.

## Approved stack

- Backend: Laravel 13, PHP 8.3+
- Frontend: React 19, TypeScript, Inertia.js, Vite and Tailwind CSS
- Database: Neon PostgreSQL
- Queue: Laravel database queue, processed in bounded cron-triggered batches on Hostinger shared hosting
- AI: Groq through a provider-independent adapter (planned)
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

Open `http://127.0.0.1:8000`. New users can register an account and create an initial business workspace; existing users can sign in. The dashboard is still a foundation screen, not a completed business dashboard.

## Verification

- `composer validate`
- `php artisan test`
- `npm run build`

A GitHub Actions workflow is configured to run these checks on pushes and pull requests. Recent runs are failing before any job steps are reported (the runner provides no step/log details), so no Composer install, Laravel test, or frontend build result is currently available. PHP syntax checks have been run locally on the new authentication and tenancy PHP files only.

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
