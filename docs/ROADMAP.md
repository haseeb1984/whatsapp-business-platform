# Implementation Roadmap

This roadmap distinguishes planned work from implemented work.

## Milestone 0 — Specification and repository foundation
- [x] Create private repository and document product requirements.
- [x] Record Hostinger Business + Neon architecture and exclude Vercel.
- [x] Add system, database, integration, security and deployment specifications.
- [ ] Verify actual Hostinger and Neon account limits.
- [ ] Generate and commit `composer.lock` and `package-lock.json`, then switch CI/deployment to deterministic locked installs (`composer install` from lock and `npm ci`).

**Status:** Documentation foundation committed.

## Milestone 1 — Laravel application bootstrap
- [x] Add Laravel 13 project manifest and application entry points.
- [x] Add React + TypeScript + Inertia application shell and Vite build configuration.
- [x] Add Neon PostgreSQL environment/configuration placeholders.
- [x] Add initial users, jobs and tenant/membership migrations.
- [x] Add health endpoints and starter CI workflow.
- [x] Install Composer and npm dependencies in GitHub Actions CI.
- [x] Run migrations and the feature suite against a disposable PostgreSQL 16 CI database.
- [x] Obtain a passing CI run for PHP tests and frontend production build.

**Status:** Source scaffold committed; CI validates Composer, runs 199 feature tests (840 assertions) on SQLite and PostgreSQL 16, audits production dependencies, verifies full migration rollback/replay on both databases, and builds the frontend. Database-level cross-tenant relational-integrity tests cover customer/conversation, staff/service, message/conversation and message-reply references; queued FAQ-job, appointment-reminder and booking-reply isolation are also tested. A separate connection/migration rehearsal against the actual Neon project remains pending.

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
- [x] Apply the fail-closed tenant concern to all 22 tenant-owned models currently present and enforce tenant ID immutability.
- [x] Implement composite tenant-aware foreign keys for cross-entity references, including customer/conversation, staff/service, messages/replies, appointments, reminders, booking sessions and reschedules.
- [ ] Complete the remaining cross-tenant negative authorization matrix for every route and mutation (read, update, delete). Added and CI-verified foreign-ID mutation tests for FAQ update/delete, inbox take/note/reply/release/assign/close, appointment hold cancellation/rescheduling, staff update/delete, staff exception read/create/update/delete, and WhatsApp connection listing/update/verification/template sync/delete; continue auditing routes without dedicated coverage.

**Status:** Authentication, password recovery, workspace selection and suspended-tenant access checks are committed and CI-tested. All 22 tenant-owned models currently in the repository use the fail-closed tenant concern; cross-entity relationships use tenant-aware composite foreign keys. Dedicated isolation tests cover queued FAQ, booking reply, reminder, Calendar sync, reschedule and Meta webhook work, including tenant-context restoration. The remaining exit work is the route-level negative authorization matrix for routes not yet covered (including read/update/delete paths) and any new models added in future modules. Verified negative tests now cover FAQ, inbox actions, appointments, staff records, staff availability exceptions, staff working-hours updates, services, holidays, WhatsApp connection management and Google Calendar staff mapping. Configure production SMTP before sending verification or password-reset emails. Latest verified code CI run [45105750596](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45105750596) passed on commit `8685dc0eec90149556db9e48ad8240a138fa304f`: 199 tests / 840 assertions on both SQLite and PostgreSQL 16, full migration rollback/replay on both, clean Composer and npm production dependency audits, and the Vite production build. The reminder text-send path now follows the same appointment-before-reminder row-lock order as template sends, delivery workers and appointment lifecycle operations, reducing lock-order inversion risk. It also now verifies that the appointment customer matches the destination conversation customer before creating a text-reminder outbox message; a dedicated negative regression test was added for this cross-customer case and is awaiting CI verification. Reminder job state transitions now also lock appointment before reminder, matching lifecycle cancellation order; late queue-failure callbacks cannot overwrite a reminder already cancelled with its appointment. A regression test covers that late-callback race. README checkpoint update is recorded in commit `129b9242cbc93af156875bcc44cf4cbb02c6cd95`. Route-level lazy loading reduced the main JavaScript chunk from ~538 kB to 445.87 kB (142.77 kB gzip), removing Vite's 500 kB chunk advisory. This is a build-level improvement; browser-level performance profiling remains pending. Live Neon connection rehearsal remains pending.

**Exit gate:** A user from tenant A cannot read, update, delete or infer tenant B data through any tested route or job.

## Milestone 3 — Business configuration and FAQ
- [x] Tenant-scoped business profile with display name, description, contact details, website, address, timezone and locale.
- [x] Business profile edit form, server-side validation and business-admin-only route protection.
- [x] Keep tenant timezone and locale synchronized with the business profile in one database transaction.
- [x] Add profile isolation and staff-access feature tests (CI passing).
- [x] Add weekly-hours validation, tenant-isolation and staff-access feature tests (CI passing).
- [x] Add holiday CRUD, duplicate-date, special-hours validation and tenant-isolation tests (CI passing).
- [x] Add service CRUD, price/duration/buffer validation, tenant-isolation and staff-access tests (CI passing).
- [x] Add staff CRUD, service assignment, cross-tenant assignment rejection and staff-access tests (CI passing).
- [x] Add staff weekly availability and tenant-isolation feature tests (CI passing).
- [x] Add FAQ publication lifecycle, validation and tenant-isolation tests (CI passing).
- [x] Weekly business hours editor with seven-day validation, tenant isolation and database constraints (CI passing).
- [x] Holiday and special-hours CRUD for a business-local date, with tenant isolation and validation (CI passing).
- [x] Service and pricing CRUD with currency code, duration, scheduling buffers, active status and tenant isolation (CI passing).
- [x] Staff CRUD and service assignment with composite tenant-aware foreign keys (CI passing).
- [x] Staff-specific weekly working hours editor with tenant-aware foreign key and validation (CI passing).
- [x] Staff-specific date exceptions and booking policy configuration (minimum notice, booking horizon, slot interval, temporary-hold duration).
- [x] FAQ CRUD, draft/published/archived lifecycle, locale validation and tenant-scoped search (CI passing).
- [ ] Dashboard forms and validation.

**Status:** Business profile, weekly hours, holiday/special-hours management, service/pricing CRUD, staff/service assignments, staff weekly availability/date exceptions, booking policies and FAQ management are committed with tenant isolation and role controls. Full business dashboards and remaining UX polish are in progress.

## Milestone 4 — WhatsApp Cloud API foundation
- [x] Persist outbound text/template messages as a durable tenant-scoped outbox record before provider delivery; dispatch after transaction commit.
- [x] Move Meta HTTP calls into a single-attempt queue worker; atomically claim `pending` messages as `sending` and persist `sent` / `failed` / `unknown` outcomes.
- [x] Recover pending rows whose queue dispatch was interrupted and mark stale `sending` rows `unknown` without automatic resend.
- [x] Automated webhook and connection tests for challenge verification, signatures, tenant mapping, encryption, duplicate delivery and Graph API verification (CI passing).
- [x] Tenant admin connection setup with encrypted access-token storage; tokens are never sent to the browser.
- [x] Meta Graph API verification of the configured phone number and its WABA membership before activation.
- [x] Meta webhook GET verification with constant-time verify-token comparison.
- [x] POST raw-body HMAC-SHA256 signature validation with constant-time comparison and payload-size limit.
- [x] Bounded retryable webhook processing job with transactional tenant context and normalized customer/conversation/message persistence (CI passing).
- [x] Inbound message and delivery-status event normalization with encrypted message content (CI passing).
- [x] Text-only outbound adapter with 24-hour customer-service-window enforcement, actor/state gate, persisted send attempt and ambiguous-outcome handling (CI passing).
- [x] Synchronize Meta message-template catalog (ID, name, language, category, approval status and components) into tenant-scoped storage; expose status in WhatsApp settings and restrict sync to verified active connections.
- [x] Send Meta-approved templates with sequential BODY parameter validation, persisted send state and provider delivery-status correlation.
- [x] Send appointment reminders outside the 24-hour window using the configured approved `appointment_reminder` template; map `{{1}}` to service name and `{{2}}` to the business-local appointment time.
- [x] Provider-side phone-number and WABA membership verification; only verified connections can become active.
- [x] Durable encrypted webhook event persistence with SHA-256 idempotency key and duplicate suppression.
- [x] Tenant routing through verified active phone_number_id; unknown/disabled connections are quarantined as unmatched.

**Current checkpoint:** Template catalog retrieval (including cursor pagination), tenant-scoped persistence, manual sync action, settings UI, approved-template sending, parameter validation and out-of-window appointment-reminder integration are implemented. Outbound text/template messages now use a durable message-row outbox: database commit precedes Meta HTTP, queue delivery is single-attempt, and ambiguous results are recorded as `unknown` rather than resent. Scheduled recovery re-dispatches old pending rows and marks abandoned `sending` rows unknown. The reminder template is selected by `META_APPOINTMENT_REMINDER_TEMPLATE` and `META_APPOINTMENT_REMINDER_LANGUAGE`; it must contain exactly two sequential BODY variables. CI passed on commit `d2c3786` with 168 tests / 704 assertions on SQLite and PostgreSQL 16, clean Composer/npm production dependency audits, and the frontend production build.

## Milestone 5 — Conversations and human handover
- [x] Manual staff replies are acknowledged as queued for delivery; provider completion is tracked asynchronously by the outbox worker.
- [x] Customer/conversation/message persistence for inbound WhatsApp events with encrypted message content.
- [x] Tenant-scoped inbox and conversation detail screens with state/search filters.
- [x] Staff assignment to active users in the same tenant; only business admins can assign or close/reopen conversations.
- [x] Staff take ownership, send manual WhatsApp replies, and save encrypted internal notes.
- [x] Outbound sender blocks automation unless the conversation is `AUTO_ACTIVE`; staff sends require a human-controlled state.
- [x] Initial automatic handover transition to `WAITING_FOR_STAFF` when approved FAQ evidence is insufficient.
- [x] Staff reply UI and backend authorization require an assigned staff user and `HUMAN_ACTIVE` state.
- [x] Explicit staff release returns an open conversation to `AUTO_ACTIVE`; reopening a closed conversation returns it to `WAITING_FOR_STAFF`.
- [ ] Staff notifications, invitations, real-time updates and audit history.
- [x] Staff release and explicit automation resume are implemented; only future inbound messages may trigger a new automated reply.

## Milestone 6 — AI and grounded FAQ assistant
- [x] Provider-independent AI interface and Groq adapter.
- [x] Published FAQ retrieval, candidate ID validation and exact approved-answer output.
- [x] Connect inbound text events to a queued grounded FAQ response workflow; recheck latest inbound and human takeover before answering.
- [x] Move unsupported/insufficient FAQ questions to `WAITING_FOR_STAFF` without generating unsupported claims.
- [x] Add same-tenant unique inbound-to-outbound correlation to prevent duplicate automated sends.
- [x] Deterministic intent routing for service catalog/pricing, today/tomorrow/weekly hours and business contact/location; exact tenant data is used before FAQ fallback.
- [ ] Allowlisted tools, expanded multilingual intents and additional business workflows.
- [x] Groq rate-limit/timeout handling with deterministic high-confidence fallback and handoff.
- [x] Prompt-injection-safe FAQ selection and tenant-isolation tests (CI passing).

**Status:** Groq remains the grounded FAQ selector. Deterministic business facts now answer common service, pricing, hours and contact questions from tenant records; missing structured facts fall back to approved FAQs, then hand off if evidence is still insufficient. Natural-language service booking phrases are routed into the booking flow. Allowlisted tools and broader multilingual intent coverage remain pending.

## Milestone 7 — Booking domain
- [x] Timezone-aware availability from tenant business hours, holiday overrides, staff weekly schedules and service duration/buffers.
- [x] Staff/service eligibility and intersection of business/staff working hours.
- [x] Staff date-specific leave/special-hours exceptions, minimum notice, booking horizon, slot interval and hold-duration policies.
- [x] Tenant-scoped appointment and appointment-event schema with composite customer/service/staff foreign keys.
- [x] Temporary booking holds with service/price snapshots, UTC intervals, buffer reservation windows and tenant-unique idempotency.
- [x] Tenant-row transactional serialization and overlap rechecks to prevent concurrent local double holds.
- [x] Bounded hold expiry processor and every-minute Laravel scheduler command.
- [x] Availability API and business appointment screen; temporary holds can be created/cancelled before Calendar side effects.
- [x] Google FreeBusy checked during availability and again before event creation; failures fail closed.
- [x] Calendar confirmation gate, deterministic event reconciliation, safe cancellation and ambiguous-outcome reservation.
- [x] Booking-first WhatsApp conversation: service/date/staff/slot selection, tenant-scoped session persistence, idempotent temporary hold, Google Calendar confirmation and truthful customer status response.
- [x] Idempotent 24-hour/2-hour reminder scheduling after Calendar confirmation.
- [x] Bounded cron dispatch, retryable tenant-scoped reminder jobs, delivery-state tracking and appointment cancellation handling.
- [x] Enforce WhatsApp 24-hour customer-service window and human-handover gate for reminder sends; outside-window reminders are held pending approved template support.
- [x] Approved template reminders outside the 24-hour window, with strict parameter mapping and safe blocking when the configured approved template is unavailable.
- [x] Rescheduling and reminder schedule replacement; pending reminder records are cancelled and new reminders are keyed to the new appointment time.
- [x] Retry-safe automated reconciliation for ambiguous Calendar updates; both old and requested slots remain reserved until the provider event is confirmed.

**Status:** Booking, reminder scheduling, cancellation, rescheduling and ambiguous Calendar update recovery are implemented and CI-tested. Reminder rows remain `processing` while the outbox delivery is pending and are finalized by the delivery worker after provider response. Live Google account validation and PostgreSQL migration rehearsal remain acceptance gates.

## Milestone 8 — Google Calendar integration
- [x] Tenant-scoped OAuth with session state, PKCE, current-user/workspace binding and expiry checks.
- [x] Verify Google account identity and required Calendar scopes.
- [x] Encrypt access/refresh tokens at rest, refresh expired tokens and revoke credentials on disconnect.
- [x] List writable calendars and configure tenant default plus per-staff calendar mappings.
- [x] FreeBusy service and local availability integration; fail closed if requested calendars cannot be checked.
- [x] Deterministic appointment event creation and event lookup after ambiguous create outcomes.
- [x] Calendar event deletion; local cancellation is persisted only after deletion succeeds. Appointment deletion now first takes the appointment row lock and marks calendar synchronization pending before the external delete call, preventing a concurrent reschedule from updating the same event. A regression test verifies the reservation is active during the provider call. Ambiguous deletion can now be retried idempotently: the operation re-enters the pending state, and the Google adapter treats an already-deleted event (HTTP 404) as success; the regression test retries an unknown deletion and verifies local cancellation completes. Calendar deletion now also distinguishes definitive provider rejection (for example HTTP 403) from ambiguous outcomes: definitive rejection records `failed`, while timeout/server/rate-limit outcomes remain `unknown` for safe retry. A regression test verifies HTTP 403 is recorded as a failed deletion rather than an ambiguous one. Stale appointments left in `calendar_pending` with `pending`/`unknown` sync state are now picked up by a tenant-scoped, bounded scheduler command after a ten-minute lease; it dispatches the existing idempotent Calendar sync job, which reconciles by deterministic event ID. Scheduler registration/overlap protection is covered by the housekeeping scheduler test. A dedicated feature-test suite now exercises stale-only dispatch, the ten-minute lease, repeat-tick suppression and explicit tenant scoping; these new tests are committed but still require CI execution. Live provider reconciliation remains pending. Google OAuth refresh now marks Google's explicit HTTP 400 `invalid_grant` token response as `reauthorization_required` while preserving encrypted credentials for an explicit reconnect; the Calendar settings page explains that reconnection is needed instead of showing a generic disconnected state. This status is not treated as connected by the provider adapter. Regression tests now simulate Google `invalid_grant` (connection requires reauthorization, encrypted refresh credential retained, subsequent calls blocked) and `invalid_client` (customer connection is not incorrectly marked revoked); CI verification remains pending.
- [x] Keep slots reserved on rate limits or ambiguous create/delete outcomes.
- [x] Event update/reschedule orchestration and tenant-scoped reconciliation worker for unknown outcomes; Google event updates are verified by read-back before local commit. A regression test now confirms replaying the same successfully synced reschedule idempotency key returns the existing appointment without issuing a second Google Calendar update. The transaction now rechecks the idempotency key under the tenant row lock, closing the concurrent first-request race before a duplicate reschedule row can be inserted.
- [ ] Validate against a dedicated live Google test account and verify revocation/permission-loss behavior.


## Milestone 9 — Dashboards, usage and platform administration
- [x] Expose global outbound outbox counts (`pending`, `sending`, `unknown`, `failed`) in the read-only platform operations dashboard.
- [x] Tenant-scoped business dashboard with role-aware actions, integration health, operational metrics, recent conversations and upcoming appointments.
- [x] Read-only platform-admin tenant, integration and queue overview, restricted by a verified-email allowlist.
- [x] Basic per-tenant message/conversation/appointment usage metrics, append-only admin mutation audit history and WhatsApp/Calendar connection health counts.
- [x] Tenant search, status filtering, pagination and CSV export with spreadsheet-formula injection protection.

**Status:** Business and platform dashboards are implemented and CI-tested. The global console provides tenant search/status filtering, pagination, CSV export, per-tenant usage counts, queue health and recent admin mutation audit events. Access is restricted to verified emails in `PLATFORM_ADMIN_EMAILS`. Detailed token/permission health, retention controls and tenant-management actions remain pending.


## Milestone 10 — Production hardening and deployment
- [x] Add a strict production preflight command for PHP version/extensions, environment/debug flags, APP_KEY, HTTPS URL, PostgreSQL TLS, database queue/after-commit/retry window/failed-job storage, persistent session/cache, secure cookies, Meta/SMTP/Groq/Google configuration and writable runtime directories.
- [x] Automated tests across critical workflows (199 tests / 840 assertions on SQLite and PostgreSQL 16).
- [x] PostgreSQL 16 migration/test compatibility matrix in CI; live Neon connectivity and TLS validation remain pending.
- [x] Automated PHP and frontend production dependency audits in GitHub Actions.
- [x] Baseline browser security headers; HSTS only on secure production requests.
- [x] Production session cookies default to `Secure` unless explicitly overridden.
- [x] Baseline application security review: tenant scoping/role gates, verified platform-email allowlist, encrypted tokens/messages/webhook payloads, signed Meta webhooks with a 1 MB cap, payload-safe audit history and mutation throttles.
- [ ] Penetration/abuse testing and deployed Hostinger security-header/WAF review (baseline headers are implemented; live endpoint verification remains pending).
- [ ] Load/latency tests for cron-backed processing.
- [ ] Neon project connection and migration rehearsal.
- [ ] Hostinger + Neon deployment rehearsal.
- [ ] Backup/restore drill and rollback plan.
- [ ] Meta production onboarding readiness review.

**Status:** Latest verified code CI on commit `53898e85b8d44edb5f3344009f7ebade1c54c8e2` passed 199 tests / 840 assertions per database on SQLite and PostgreSQL 16, including full migration rollback/replay. Composer/npm production audits report no advisories. Durable outbound Meta delivery uses a transactional outbox, after-commit dispatch, duplicate-job claiming, pending recovery, stale `sending` reconciliation and explicit `unknown` handling; delivery status is finalized from worker/webhook evidence, without claiming provider-side exactly-once delivery. Route-level lazy loading keeps the main JavaScript chunk at 445.87 kB (142.76 kB gzip); browser-level performance profiling remains pending. Baseline browser security headers, Secure production session cookies, tenant isolation, token/payload encryption, webhook HMAC/size limits, admin audit logging, high-cost mutation throttles and strict runtime preflight are implemented and CI-tested. Penetration/abuse testing, live Neon TLS/credential validation, Hostinger deployment, queue/cron configuration, backups and rollback remain acceptance gates.

## Development rules

- Work milestone-by-milestone and do not skip acceptance gates.
- Prefer small, reviewable commits.
- Never overwrite unrelated projects or credentials.
- Do not add Vercel.
- Do not add paid infrastructure/providers without approval.
- Keep README and docs updated with actual implementation status.
- After each milestone, report changed files, tests run, results, limitations and next milestone.

## Latest verified implementation checkpoint (2026-11-23)

- Verified code commit: `53898e85b8d44edb5f3344009f7ebade1c54c8e2`.
- CI: [run 45104012689](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45104012689) passed.
- Automated suite: 199 tests / 840 assertions on SQLite and PostgreSQL 16; full migration rollback/replay passed on both.
- Composer/npm production dependency audits passed; Vite production build passed.
- The build manifest check confirms 21 lazy-loaded Inertia page chunks; main JavaScript bundle is 445.87 kB (142.76 kB gzip).
- Outbound text/template messages are persisted before network delivery; `DeliverWhatsAppMessage` performs the Meta request outside the creation transaction and claims each outbox row once.
- Pending outbox records are re-dispatched after five minutes if a queue dispatch was lost; abandoned `sending` records are marked `unknown` after ten minutes and never automatically resent.
- Appointment reminder status is finalized by the outbox worker; staff UI messaging says replies are queued, and conversation history renders queued/sending/sent/delivered/read/failed/unknown states with clear labels. An already-authorized staff reply remains deliverable if automation is released before the worker runs; a queued automation reply is cancelled if a human takes over first.
- Platform operations now reports outbound pending/sending/unknown/failed counts without exposing message content or customer data.
- The transaction-boundary test confirms Meta HTTP executes after the message-creation transaction commits; duplicate queue-job tests confirm only one provider request is made. Scheduler regression tests verify all four housekeeping commands are registered every minute with overlap protection.
- `platform:preflight --strict` checks PHP 8.3+, production/debug flags, APP_KEY, HTTPS URL, PostgreSQL TLS, database queue after-commit, secure session cookies, Meta app/webhook credentials, SMTP, Groq, Google OAuth and writable runtime directories without printing secret values.
- Remaining production gates: committed dependency lockfiles, actual Neon TLS/connectivity and migration rehearsal, Hostinger runtime/deployment rehearsal, live Meta/Google validation, load/abuse testing, browser performance profiling, and backup/restore drill.
\n\n## Latest unverified change (2026-11-23)\n\n- Added `CalendarPendingRecoveryCommandTest` covering stale candidate dispatch, fresh/failed row exclusion, lease refresh, duplicate scheduler-tick suppression and tenant-scoped execution.\n- The Calendar job constructor was inspected and confirmed to accept `(tenantId, appointmentId)`, matching the recovery command dispatch order.\n- The follow-up fixture-schema and PHP namespace corrections are included through commit `071ae1e94c6cbeb5a25fba5e9c00eceb36d56e8e`. No CI result has been verified for this test change; these tests are not claimed as passing.\n

## Google Calendar reauthorization hardening (2026-11-23)

- OAuth callback no longer reuses a previously stored refresh token when the connection is already marked `reauthorization_required`. This prevents a revoked token from being silently reactivated when Google omits a new refresh token from the consent response.
- Added a regression test proving a reconnect response without a new refresh token leaves the old connection in `reauthorization_required` and preserves its encrypted credentials for a deliberate recovery path.
- The Calendar settings page already derives its connected state from the connection status and exposes the reconnect action when reauthorization is required.
- Code and test changes are committed; CI execution and live Google account validation remain outstanding.


## Calendar reconnect usability follow-up (2026-11-23)

- The Calendar settings UI now provides a deliberate **Clear saved authorization** action when Google returns `invalid_grant`. This makes the documented recovery path actionable if Google does not return a new offline refresh token on reconnect.
- Added a success-path regression test proving a fresh Google refresh token replaces the revoked credential and restores connection status to `connected`.
- CI and live Google OAuth validation remain outstanding; do not treat the new tests as passing until the workflow reports success.
- Follow-up regression coverage also verifies that a new refresh token restores `connected`, the settings page receives `reauthorization_required`, and clearing a revoked connection removes encrypted credentials even when Google cannot confirm revocation.



## Meta webhook worker-crash recovery (2026-11-23)

- Added `webhooks:reconcile-stale-processing`, a bounded recovery command for tenant-owned Meta webhook events left in `processing` after a worker is terminated.
- Only events belonging to active tenants and older than the ten-minute lease are reset to `pending`; the command locks and rechecks each row, updates its lease timestamp, and dispatches the existing idempotent processor after commit.
- The command is scheduled every minute with overlap protection. A tenant option supports scoped operations.
- Added regression tests for stale-vs-fresh selection, lease renewal/no duplicate requeue, and cross-tenant isolation; scheduler coverage now requires the command.
- Added a guard and regression test so a late queue failure callback cannot overwrite an event that already committed as `processed`.
- CI execution and real Meta webhook validation remain pending. This recovery does not change outbound delivery semantics: ambiguous provider sends remain `unknown` and are never automatically resent.

## Delivery and recovery test follow-up (2026-11-23)

- Fixed the Calendar pending-recovery test fixture to reuse the tenant's unique Consultation service, business-hours row and WhatsApp customer rather than inserting duplicate unique records for each appointment.
- Expanded outbound delivery regression coverage for Meta HTTP 400 (definitive failure), HTTP 429/503 (conservative ambiguous outcome), and HTTP 200 without a provider message ID (ambiguous outcome). The implementation continues to avoid automatic resend when acceptance cannot be established.
- Changes are committed on main; CI has not yet reported a result for the latest commits. Do not count these added tests as passing until the full SQLite/PostgreSQL/build workflow completes.
- Outbound Meta delivery classification now treats HTTP 408 as ambiguous (alongside 429 and 5xx) for both free-form and approved-template sends. A provider timeout response does not prove that Meta did not accept the message, so the outbox must retain `unknown` and must not auto-retry it. Regression coverage includes HTTP 408.
- Meta webhook routing now resolves only connections belonging to active tenants. Events received for suspended tenants are retained as `unmatched` and are not dispatched to tenant workers; a regression test covers the quarantine behavior.


## Webhook processing durability review (2026-11-23)

- A follow-up code review found that `ProcessMetaWebhookEvent::handle()` currently writes `processing`, applies the inbound/status effects, and writes `processed` inside one database transaction. This preserves atomicity and idempotency, but means `processing` is not normally a durable intermediate state: a worker crash before commit rolls the transaction back to `pending`.
- Consequently, `webhooks:reconcile-stale-processing` is defensive for rows left by older/manual flows, but does not by itself prove recovery of a worker crash in the current atomic handler. Do not describe it as a verified active crash-recovery path until the claim/lease is deliberately separated from the processing transaction and regression-tested for duplicate delivery, concurrent workers, and crash points.
- No runtime behavior was changed in this review. The existing atomic transaction is retained rather than weakening webhook idempotency without a full recovery test matrix. Latest code CI remains unverified after the recent commits.


## Meta webhook duplicate-worker regression coverage (2026-11-23)

- Added a feature test that invokes the same inbound webhook job twice for one persisted provider event and verifies the event is processed once, the provider message ID creates one inbound message, and only one customer/conversation is created.
- This specifically exercises the current atomic processing boundary: a second worker execution observes the committed `processed` state and exits without repeating side effects.
- This is sequential duplicate-execution coverage, not a substitute for a real concurrent-worker/database-lock test. CI has not yet run for this branch; do not count the test as passing until the workflow completes.


- Follow-up race-hardening: webhook job exception recovery and terminal `failed()` callbacks now lock the webhook-event row and recheck `processed` inside a transaction before writing. This prevents a stale callback from overwriting a concurrently committed successful result. A regression assertion invokes a late failure callback after processing and verifies the event remains processed. CI remains pending.
