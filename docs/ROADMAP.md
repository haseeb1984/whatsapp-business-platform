# Implementation Roadmap

This roadmap distinguishes planned work from implemented work. At repository initialization, all application features below are **planned**, not implemented.

## Milestone 0 — Specification and repository foundation
- [x] Create private repository and document product requirements.
- [x] Record Hostinger Business + Neon architecture and exclude Vercel.
- [ ] Review exact Hostinger plan limits and Neon project configuration.
- [ ] Agree initial service-level expectations for response latency and throughput.

**Exit gate:** technical design reviewed; deployment constraints understood; no paid upgrade required without approval.

## Milestone 1 — Laravel application bootstrap
- [ ] Initialize Laravel application with compatible PHP/Laravel versions.
- [ ] Configure PostgreSQL/Neon connection and migrations.
- [ ] Add React + TypeScript + Inertia frontend build.
- [ ] Add health endpoint, structured logging, environment example, CI checks.
- [ ] Add baseline feature/unit tests.

**Exit gate:** clean install, migrations, frontend build, health check, and tests pass locally.

## Milestone 2 — Identity, tenancy, and authorization
- [ ] Implement tenants, users, memberships, roles, and permissions.
- [ ] Implement tenant resolution and scoped data access.
- [ ] Add composite tenant-aware foreign keys and indexes.
- [ ] Add cross-tenant negative authorization tests.

**Exit gate:** a user from tenant A cannot read, update, delete, or infer tenant B data through any tested route/job.

## Milestone 3 — Business configuration and FAQ
- [ ] Business profile, timezone, hours, holidays, services, staff, policies.
- [ ] FAQ CRUD, publish/archive lifecycle, tenant-scoped search.
- [ ] Dashboard forms and validation.

**Exit gate:** business facts can be configured and retrieved with tenant isolation.

## Milestone 4 — WhatsApp Cloud API foundation
- [ ] Meta webhook GET verification.
- [ ] POST raw-body HMAC signature validation.
- [ ] Durable event persistence and idempotent event handler.
- [ ] Tenant routing through verified phone_number_id mapping.
- [ ] Outbound text/template adapter and status webhooks.
- [ ] Integration tests using Meta test assets.

**Exit gate:** duplicate webhooks do not duplicate messages/actions; valid/invalid signatures behave correctly.

## Milestone 5 — Conversations and human handover
- [ ] Customer/conversation/message persistence.
- [ ] Staff assignment, notes, and conversation states.
- [ ] Human-active hard gate before every automated send.
- [ ] Staff release and controlled automation resume.

**Exit gate:** queued bot replies cannot send after a staff takeover.

## Milestone 6 — AI and grounded FAQ assistant
- [ ] AI provider interface and Groq adapter.
- [ ] Intent routing, retrieval, response validation, allowlisted tools.
- [ ] Rate-limit/timeout handling and deterministic fallback.
- [ ] Prompt-injection and tenant-isolation tests.

**Exit gate:** unsupported facts are not fabricated; provider failure does not break deterministic flows.

## Milestone 7 — Booking domain
- [ ] Availability engine and timezone-aware slot calculation.
- [ ] Staff/service eligibility, hours, exceptions, buffers.
- [ ] Pending holds, concurrency locks, idempotency.
- [ ] Booking/reschedule/cancel state machine.

**Exit gate:** concurrency tests prove overlapping reservations are prevented.

## Milestone 8 — Google Calendar integration
- [ ] OAuth flow and encrypted token storage.
- [ ] Multiple staff calendar mappings.
- [ ] FreeBusy checks and event create/update/delete.
- [ ] Reconciliation for ambiguous/partial failures.
- [ ] Reminder scheduling and cancellation-aware processing.

**Exit gate:** no false booking confirmation; partial Calendar failures recover safely.

## Milestone 9 — Dashboards, usage, and platform administration
- [ ] Business dashboard and role-based screens.
- [ ] Platform admin tenant/integration/queue views.
- [ ] Usage metrics, audit log, integration health.
- [ ] Search, filtering, pagination, export controls.

**Exit gate:** all actions are backend-authorized and audited where sensitive.

## Milestone 10 — Production hardening and deployment
- [ ] Automated tests across critical workflows.
- [ ] Security review and dependency checks.
- [ ] Load/latency tests for cron-backed processing.
- [ ] Hostinger + Neon deployment rehearsal.
- [ ] Backup/restore drill and rollback plan.
- [ ] Meta production onboarding readiness review.

**Exit gate:** operational limits meet measured requirements; production launch checklist is complete.

## Development rules

- Work milestone-by-milestone; do not silently skip acceptance gates.
- Prefer small reviewable commits.
- Never overwrite unrelated projects or credentials.
- Do not add Vercel.
- Do not add paid infrastructure/providers without approval.
- Keep README and docs updated with actual implementation status.
- After each milestone, report changed files, tests run, results, known limitations, and next milestone.
