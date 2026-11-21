# WhatsApp Business Platform

A reusable, multi-tenant WhatsApp Business automation platform for businesses using the official Meta WhatsApp Business Cloud API.

## Project status

**Current stage:** Foundation and technical specification  
**Deployment target:** Hostinger Business shared hosting + Neon PostgreSQL  
**AI provider:** Groq first, behind a provider-independent interface  
**WhatsApp integration:** Official Meta Cloud API only  
**Frontend:** React + TypeScript + Inertia.js (built assets served by Laravel)  
**Backend:** Laravel / PHP  
**Queue strategy:** Database-backed jobs processed by scheduled cron batches on shared hosting

> Vercel is intentionally not part of this project. Redis, Docker, always-on workers, and WebSockets are not assumed to be available on Hostinger Business shared hosting.

## Product goal

One codebase serves multiple independent businesses. Each business configures its own WhatsApp connection, business profile, FAQs, services, staff, calendars, business hours, automation settings, and users. Tenant-specific behavior is configuration-driven; a separate bot or deployment is not required per business.

## Core capabilities

- Multi-tenant business accounts with strict tenant isolation and role-based access.
- Official Meta WhatsApp Cloud API webhooks and outbound messaging.
- FAQ assistant grounded in each business's approved knowledge and structured data.
- Human handover that reliably pauses automated replies until staff releases the conversation.
- Appointment discovery, availability checks, booking, rescheduling, cancellation, and reminders.
- Google Calendar integration with conflict checks and reconciliation.
- Platform administration, business dashboards, usage tracking, audit logs, and operational visibility.
- Provider-independent AI integration with Groq as the initial provider and deterministic fallbacks.

## Architecture at a glance

`WhatsApp user → Meta Cloud API → Laravel webhook → durable event/job storage → tenant-aware conversation workflow → FAQ / booking / human handover → Meta Cloud API`

Laravel uses Neon PostgreSQL as the system of record. Scheduled cron invocations process bounded batches of queued work and reminders. Webhook requests should verify, persist, and acknowledge quickly; they must not wait for a full AI or calendar workflow.

## Documentation

| Document | Purpose |
|---|---|
| [Requirements](docs/REQUIREMENTS.md) | Product scope, actors, functional and non-functional requirements |
| [Architecture](docs/ARCHITECTURE.md) | Components, deployment topology, runtime constraints and key decisions |
| [Database design](docs/DATABASE.md) | Tenant model, entities, constraints and indexing |
| [WhatsApp integration](docs/WHATSAPP.md) | Webhook verification, signatures, idempotency and messaging |
| [AI design](docs/AI.md) | Provider abstraction, grounded FAQ behavior and safe tool execution |
| [Booking and Calendar](docs/BOOKING-CALENDAR.md) | Availability, booking lifecycle and Google Calendar consistency |
| [Security](docs/SECURITY.md) | Tenant isolation, secrets, access control and operational safeguards |
| [Deployment](docs/DEPLOYMENT.md) | Hostinger + Neon deployment approach and environment configuration |
| [Roadmap](docs/ROADMAP.md) | Milestones, dependencies and acceptance criteria |

## Development principles

1. Official APIs only; never automate WhatsApp Web.
2. Tenant isolation is enforced server-side on every request, job, query, and integration.
3. Never invent business facts, prices, policies, staff availability, or booking confirmations.
4. Never tell a customer an appointment is confirmed until the calendar operation and local persistence have succeeded.
5. Persist inbound webhook events before acknowledging them; deduplicate retries.
6. Human handover is a hard automation gate, not merely a prompt instruction.
7. Keep integrations behind interfaces and make failures observable and recoverable.
8. No paid services or plan upgrades without explicit approval.
9. Document implemented behavior separately from planned behavior; do not claim unbuilt features as complete.

## Local setup

The repository currently contains the technical specification. Application code and exact dependency versions will be added in implementation milestones.

Expected prerequisites for the planned Laravel application:

- PHP version and extensions compatible with the selected Laravel release
- Composer
- Node.js and npm for frontend asset builds
- PostgreSQL-compatible database access (Neon)
- Meta developer app and WhatsApp Cloud API test credentials for integration testing
- Google Cloud OAuth credentials for Calendar integration tests
- Groq API key for AI integration tests

Never commit real credentials. Use a local `.env` file and keep `.env.example` limited to placeholder values.

## Current limitations to design around

Hostinger Business shared hosting is not treated as an environment for always-running queue workers, Redis services, Docker, or WebSockets. The initial deployment therefore uses bounded database-queue processing triggered by cron. This introduces scheduling latency and must be load-tested. If measured throughput or latency cannot meet the product's service goals, move the worker/API runtime to a VPS or another explicitly approved compute service while retaining the same application and Neon database architecture.

Neon plan limits, Hostinger plan-specific resource limits, PHP version, cron availability, process limits, and outbound network access must be verified in the actual accounts before production launch.
