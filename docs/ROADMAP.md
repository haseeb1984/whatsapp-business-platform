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
- [x] Install Composer and npm dependencies in GitHub Actions CI.
- [ ] Run migrations against a disposable PostgreSQL database.
- [x] Obtain a passing CI run for PHP tests and frontend production build.

**Status:** Source scaffold committed; CI dependency installation, feature tests and frontend build pass. PostgreSQL migration rehearsal remains pending.

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
- [x] Add and run feature tests for registration, login/logout, password recovery, workspace selection, cross-tenant selection rejection and role authorization.
- [x] Apply business-admin role middleware to business profile and weekly-hours configuration routes.
- [x] Add a fail-closed `BelongsToTenant` Eloquent concern with context-derived tenant assignment and tenant ID immutability.
- [x] Block tenant reassignment through Eloquent bulk `update()` operations on tenant-owned models.
- [x] Add explicit background-work tenant context lifecycle with active-tenant validation and context restoration.
- [ ] Apply the concern to every tenant-owned model as modules are created.
- [ ] Implement tenant-scoped repositories/policies and composite tenant-aware foreign keys.
- [ ] Add cross-tenant negative authorization tests for read, update, delete and background jobs.

**Status:** Initial authentication, password recovery, workspace selection and suspended-tenant access checks are committed and CI-tested. Current business modules use business-admin role middleware. Remaining work is broader tenant-model coverage, composite foreign keys for all cross-entity references and end-to-end queued-job isolation. Configure production SMTP before sending verification or password-reset emails. The current CI run passes (70 tests / 255 assertions).

**Exit gate:** A user from tenant A cannot read, update, delete or infer tenant B data through any tested route or job.

## Milestone 3 — Business configuration and FAQ
- [x] Tenant-scoped business profile with display name, description, contact details, website, address, timezone and locale.
- [x] Business profile edit form, server-side validation and business-admin-only route protection.
- [x] Keep tenant timezone and locale synchronized with the business profile in one database transaction.
- [x] Add profile isolation and staff-access feature tests (CI passing).
- [x] Add weekly-hours validation, tenant-isolation and staff-access feature tests (CI passing).
- [x] Add holiday CRUD, duplicate-date, special-hours validation and tenant-isolation tests (CI passing).
- [x] Add service CRUD, price/duration/buffer validation, tenant-isolation and staff-access tests (CI passing).
- [x] Add staff CRUD, service assignment, cross-tenant assignment rejection and staff-access tests (authored; execution pending).
- [x] Add staff weekly availability and tenant-isolation feature tests (authored; execution pending).
- [x] Add FAQ publication lifecycle, validation and tenant-isolation tests (authored; execution pending).
- [x] Weekly business hours editor with seven-day validation, tenant isolation and database constraints (CI passing).
- [x] Holiday and special-hours CRUD for a business-local date, with tenant isolation and validation (CI passing).
- [x] Service and pricing CRUD with currency code, duration, scheduling buffers, active status and tenant isolation (CI passing).
- [x] Staff CRUD and service assignment with composite tenant-aware foreign keys (tests authored; execution pending).
- [x] Staff-specific weekly working hours editor with tenant-aware foreign key and validation (tests authored; execution pending).
- [ ] Staff-specific holidays/exceptions and business policies.
- [x] FAQ CRUD, draft/published/archived lifecycle, locale validation and tenant-scoped search (tests authored; execution pending).
- [ ] Dashboard forms and validation.

**Status:** Business profile, weekly hours, holiday/special-hours management, service/pricing CRUD, staff/service assignments, staff weekly availability and FAQ management are committed with admin-only routes. The current CI suite passes (56 tests / 199 assertions). Staff date-specific exceptions, business policies and full dashboard workflows remain in progress.

## Milestone 4 — WhatsApp Cloud API foundation
- [x] Automated webhook and connection tests for challenge verification, signatures, tenant mapping, encryption, duplicate delivery and Graph API verification (CI passing).
- [x] Tenant admin connection setup with encrypted access-token storage; tokens are never sent to the browser.
- [x] Meta Graph API verification of the configured phone number and its WABA membership before activation.
- [x] Meta webhook GET verification with constant-time verify-token comparison.
- [x] POST raw-body HMAC-SHA256 signature validation with constant-time comparison and payload-size limit.
- [x] Bounded retryable webhook processing job with transactional tenant context and normalized customer/conversation/message persistence (CI passing).
- [x] Inbound message and delivery-status event normalization with encrypted message content (CI passing).
- [ ] Outbound text/template adapter and sending workflow.
- [x] Provider-side phone-number and WABA membership verification; only verified connections can become active.
- [x] Durable encrypted webhook event persistence with SHA-256 idempotency key and duplicate suppression.
- [x] Tenant routing through verified active phone_number_id; unknown/disabled connections are quarantined as unmatched.

## Milestone 5 — Conversations and human handover
- [x] Customer/conversation/message persistence for inbound WhatsApp events with encrypted message content.
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
