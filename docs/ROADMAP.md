# Implementation Roadmap

This roadmap distinguishes planned work from implemented work.

## Milestone 0 — Specification and repository foundation
- [x] Create private repository and document product requirements.
- [x] Record Hostinger Business + Neon architecture and exclude Vercel.
- [x] Add system, database, integration, security and deployment specifications.
- [ ] Verify actual Hostinger and Neon account limits.

**Status:** Documentation foundation committed.

## Milestone 1 — Laravel application bootstrap
- [x] Add Laravel 13 project manifest and application entry points.
- [x] Add React + TypeScript + Inertia application shell and Vite build configuration.
- [x] Add Neon PostgreSQL environment/configuration placeholders.
- [x] Add initial users, jobs and tenant/membership migrations.
- [x] Add health endpoints and starter CI workflow.
- [ ] Install Composer and npm dependencies in a runtime environment.
- [ ] Run migrations against a disposable PostgreSQL database.
- [ ] Obtain a passing CI run and fix any build/runtime errors.

**Status:** Source scaffold committed; runtime verification is pending. Do not treat this milestone as accepted until the unchecked items pass.

## Milestone 2 — Identity, tenancy, and authorization
- [x] Implement email/password registration, login and logout with session regeneration/invalidation.
- [x] Implement password reset request and token-based password update flow.
- [x] Implement signed email verification links, resend flow and verified-account enforcement.
- [x] Create a business workspace and active owner membership transactionally during registration.
- [x] Add a tenant context value object and middleware that resolves only active memberships.
- [x] Automatically select a user's sole active tenant; require explicit selection for multiple tenants.
- [x] Reject a session-selected tenant that the authenticated user does not belong to.
- [x] Block suspended business workspaces even when the user membership itself is active.
- [x] Exclude suspended tenants from workspace selection, switching and automatic login selection.
- [x] Restore/clear request tenant context after each request to prevent long-lived process context leakage.
- [x] Route newly registered users to email verification before dashboard access.
- [x] Protect the dashboard with authentication and tenant-context middleware.
- [x] Add workspace selection/switching UI and validate active membership server-side.
- [x] Add tenant role context and role-restricted middleware for tenant routes.
- [x] Add feature tests for registration, login/logout, password recovery, workspace selection, cross-tenant selection rejection and role authorization.
- [x] Apply business-admin role middleware to business profile and weekly-hours configuration routes.
- [x] Add a fail-closed `BelongsToTenant` Eloquent concern with context-derived tenant assignment and tenant ID immutability.
- [x] Block tenant reassignment through Eloquent bulk `update()` operations on tenant-owned models.
- [x] Add explicit background-work tenant context lifecycle with active-tenant validation and context restoration.
- [ ] Apply the concern to every tenant-owned model as modules are created.
- [ ] Implement tenant-scoped repositories/policies and composite tenant-aware foreign keys.
- [ ] Add cross-tenant negative authorization tests for read, update, delete and background jobs.

**Status:** Initial authentication, password recovery, workspace selection and suspended-tenant access checks committed. Applying role restrictions to each business module, database constraints and full isolation acceptance tests remain outstanding. Configure production SMTP before sending verification or password-reset emails. Automated tests have been authored but are not yet executed in a working Laravel runtime.

**Exit gate:** A user from tenant A cannot read, update, delete or infer tenant B data through any tested route or job.

## Milestone 3 — Business configuration and FAQ
- [x] Tenant-scoped business profile with display name, description, contact details, website, address, timezone and locale.
- [x] Business profile edit form, server-side validation and business-admin-only route protection.
- [x] Keep tenant timezone and locale synchronized with the business profile in one database transaction.
- [x] Add profile isolation and staff-access feature tests (authored; execution pending).
- [x] Add weekly-hours validation, tenant-isolation and staff-access feature tests (authored; execution pending).
- [x] Add holiday CRUD, duplicate-date, special-hours validation and tenant-isolation tests (authored; execution pending).
- [x] Add service CRUD, price/duration/buffer validation, tenant-isolation and staff-access tests (authored; execution pending).
- [x] Weekly business hours editor with seven-day validation, tenant isolation and database constraints (tests authored; execution pending).
- [x] Holiday and special-hours CRUD for a business-local date, with tenant isolation and validation (tests authored; execution pending).
- [x] Service and pricing CRUD with currency code, duration, scheduling buffers, active status and tenant isolation (tests authored; execution pending).
- [ ] Staff and policies.
- [ ] FAQ CRUD, publish/archive lifecycle and tenant-scoped search.
- [ ] Dashboard forms and validation.

**Status:** Business profile, weekly hours, holiday/special-hours management and service/pricing CRUD are committed, including admin-only routes and authored feature tests. Runtime verification remains pending; holidays, services, staff, FAQs and full dashboards remain in progress.

## Milestone 4 — WhatsApp Cloud API foundation
- [ ] Meta webhook GET verification.
- [ ] POST raw-body HMAC signature validation.
- [ ] Durable event persistence and idempotent event handler.
- [ ] Tenant routing through verified phone_number_id mapping.
- [ ] Outbound text/template adapter and status webhooks.
- [ ] Integration tests using Meta test assets.

## Milestone 5 — Conversations and human handover
- [ ] Customer/conversation/message persistence.
- [ ] Staff assignment, notes and conversation states.
- [ ] Human-active hard gate before every automated send.
- [ ] Staff release and controlled automation resume.

## Milestone 6 — AI and grounded FAQ assistant
- [ ] AI provider interface and Groq adapter.
- [ ] Intent routing, retrieval, response validation and allowlisted tools.
- [ ] Rate-limit/timeout handling and deterministic fallback.
- [ ] Prompt-injection and tenant-isolation tests.

## Milestone 7 — Booking domain
- [ ] Availability engine and timezone-aware slot calculation.
- [ ] Staff/service eligibility, hours, exceptions and buffers.
- [ ] Pending holds, concurrency locks and idempotency.
- [ ] Booking/reschedule/cancel state machine.

## Milestone 8 — Google Calendar integration
- [ ] OAuth flow and encrypted token storage.
- [ ] Multiple staff calendar mappings.
- [ ] FreeBusy checks and event create/update/delete.
- [ ] Reconciliation for ambiguous/partial failures.
- [ ] Reminder scheduling and cancellation-aware processing.

## Milestone 9 — Dashboards, usage and platform administration
- [ ] Business dashboard and role-based screens.
- [ ] Platform admin tenant/integration/queue views.
- [ ] Usage metrics, audit log and integration health.
- [ ] Search, filtering, pagination and export controls.

## Milestone 10 — Production hardening and deployment
- [ ] Automated tests across critical workflows.
- [ ] Security review and dependency checks.
- [ ] Load/latency tests for cron-backed processing.
- [ ] Hostinger + Neon deployment rehearsal.
- [ ] Backup/restore drill and rollback plan.
- [ ] Meta production onboarding readiness review.

## Development rules

- Work milestone-by-milestone and do not skip acceptance gates.
- Prefer small, reviewable commits.
- Never overwrite unrelated projects or credentials.
- Do not add Vercel.
- Do not add paid infrastructure/providers without approval.
- Keep README and docs updated with actual implementation status.
- After each milestone, report changed files, tests run, results, limitations and next milestone.
