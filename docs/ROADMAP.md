# Implementation Roadmap

This roadmap distinguishes planned work from implemented work.

## Milestone 0 — Specification and repository foundation
- [x] Create private repository and document product requirements.
- [x] Record Hostinger Business + Neon architecture and exclude Vercel.
- [x] Add system, database, integration, security and deployment specifications.
- [ ] Verify actual Hostinger and Neon account limits.
- [x] Generate and commit `composer.lock` and `package-lock.json`; CI uses deterministic locked installs (`composer install` from lock and `npm ci`).

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
- [x] Expand the tenant-role negative authorization matrix across business configuration, staff schedules, FAQs, WhatsApp connection management, Calendar disconnect, booking policies/lifecycle and conversation operations; keep future routes covered as they are added.

**Status:** Authentication, workspace selection, suspended-tenant checks, fail-closed tenant scoping, all 22 current tenant-owned models and tenant-aware composite foreign keys are implemented. Dedicated regression tests cover tenant-owned model read/update/delete isolation, queued jobs and tenant-context restoration. The route-level role-denial matrix was expanded in PR #19 to cover weekly hours, holidays, services, staff CRUD/working hours, FAQ read/update/delete, WhatsApp connection update/verification/template sync/delete and Calendar disconnect, complementing earlier coverage for profile, booking, staff exceptions, Calendar OAuth/mapping and inbox actions. PR #19 full CI [run 45299514301](https://github.com/actions/runs/45299514301) passed; it was squash-merged as `575d756fca1d4261ce4336f6819af641620baf53`, and post-merge `main` CI [run 45299692443](https://github.com/actions/runs/45299692443) passed. Staff role denial and cross-tenant foreign-ID isolation are separate controls; continue adding regression tests whenever a new route or model is introduced. The remaining exit work is live Neon validation and future-route regression maintenance, not the PR #19 matrix. Configure production SMTP before sending verification or password-reset emails.

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
- [x] Dashboard forms and validation: reusable accessible validation summaries are rendered across business profile, weekly hours, holidays, services, staff CRUD/schedules/exceptions, FAQs, WhatsApp connections, appointment policy, conversation assignment/reply/notes, and Google Calendar default/staff mappings; calendar IDs are validated server-side and writable-calendar failures are returned as field errors.

**Status:** Business profile, weekly hours, holiday/special-hours management, service/pricing CRUD, staff/service assignments, staff weekly availability/date exceptions, booking policies and FAQ management are committed with tenant isolation and role controls. Reusable accessible validation summaries now surface server-side errors across business configuration, conversation and Google Calendar mapping forms. Calendar mapping validation is covered by malformed-ID and read-only-calendar regressions; remaining dashboard polish still needs review.

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
- [x] Staff invitations: tenant-scoped invitation creation/acceptance/revocation, membership lifecycle controls and queued email delivery with bounded retries.
- [x] Audit history: append-only tenant/platform mutation audit records and admin history views.
- [x] Near-real-time conversation inbox refresh (15-second visible-tab polling) and conversation detail refresh (8-second visible-tab polling) for new messages, assignment/state changes, notes and delivery status.
- [x] Per-workspace self-service email preferences for conversation assignments and staff handover requests; queued email notifications re-check active membership and the member's selected preference before selecting the mail channel.
- [x] Persistent in-app notification center: tenant-filtered database notifications, unread count, mark-one/read-all actions, and 15-second visible-tab refresh; email delivery remains independently configurable.
- [ ] Push/WebSocket delivery for lower-latency updates; current in-app inbox and conversation screens use 8/15-second polling.
- [x] Staff release and explicit automation resume are implemented; only future inbound messages may trigger a new automated reply.

## Milestone 6 — AI and grounded FAQ assistant
- [x] Provider-independent AI interface and Groq adapter.
- [x] Published FAQ retrieval, candidate ID validation and exact approved-answer output.
- [x] Connect inbound text events to a queued grounded FAQ response workflow; recheck latest inbound and human takeover before answering.
- [x] Move unsupported/insufficient FAQ questions to `WAITING_FOR_STAFF` without generating unsupported claims.
- [x] Add same-tenant unique inbound-to-outbound correlation to prevent duplicate automated sends.
- [x] Deterministic intent routing for service catalog/pricing, today/tomorrow/weekly hours and business contact/location; exact tenant data is used before FAQ fallback.
- [x] Strict model-selected allowlisted read-only tools: list/search active services, retrieve today/tomorrow/weekly hours, and retrieve configured public contact details. Tool names, exact argument keys/types, output decisions and tenant-scoped execution are validated server-side; unknown tools and malformed arguments fail closed. The tool selector runs only after deterministic routing and approved FAQ retrieval hand off.
- [ ] Broader locale/intent coverage and additional booking-related business workflows; current tools are read-only and cannot create, change or cancel bookings.
- [x] Groq rate-limit/timeout handling with deterministic high-confidence fallback and handoff.
- [x] Prompt-injection-safe FAQ selection and tenant-isolation tests (CI passing).

**Status:** Groq remains the grounded FAQ selector and can now select only a server-allowlisted read-only business tool after FAQ evidence is insufficient. The backend validates the exact tool name and argument schema, executes tenant-scoped reads, and constructs the reply from stored business data rather than model-generated facts. Tests cover unknown tools, extra/invalid arguments, cross-tenant service isolation, active-service filtering and the queued WhatsApp reply path. Booking mutations remain outside the tool allowlist and continue through the dedicated booking workflow.

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

**Status:** Business and platform dashboards are implemented and CI-tested. The global console provides tenant search/status filtering, pagination, CSV export, per-tenant usage counts, queue health and recent admin mutation audit events. It also reports aggregate WhatsApp verification states and Google Calendar reauthorization, expired-token and missing-refresh-token counts without exposing credentials. Access is restricted to verified emails in `PLATFORM_ADMIN_EMAILS`. Live provider permission validation, usage controls, retention controls and tenant-management actions remain pending.


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


## Outbound template timeout classification follow-up (2026-11-23)

- Code review caught that free-form text sends classified HTTP 408 as ambiguous, but approved-template sends still treated it as a definite failure. Template sends now classify 408 as `unknown`, matching timeout semantics and the no-automatic-resend invariant.
- Corrected the existing response-sequence fixture so the HTTP 408, 429, 503 and missing-provider-ID cases each receive their intended response.
- Added a template-specific HTTP 408 regression test that invokes the delivery job twice and asserts one provider request and an `unknown` result.
- These changes are in PR #2 and await CI; no provider-side exactly-once claim is made.


- Added a second provider-level idempotency regression: two distinct persisted webhook deliveries carrying the same Meta provider message ID are processed independently, while producing one customer, one open conversation and one inbound message. This complements same-job replay coverage. CI is pending for the latest branch head.


- CI attempt `45128253359` reached PHP test loading and stopped on an escaped `RuntimeException` syntax error in the new late-callback assertion; no feature tests ran in that attempt. The reference has been corrected on the branch. A fresh CI run `45128391052` is now in progress for the corrected head; results are not yet known.


- CI run `45128423921` executed the SQLite suite: 218 tests passed (946 assertions), 4 failed. The failures exposed two test-only tenant-context omissions in the new webhook assertions, a globally unique Meta phone-number fixture reused across the new outbound multi-workspace loop, and the template send path still missing HTTP 408 classification. Corrected the scoped reads, isolated phone-number fixtures, and aligned both text/template 408 classification. A fresh CI run is required; PostgreSQL and production build stages have not yet been validated for this PR head.


- Follow-up PostgreSQL CI on the corrected webhook fixtures reached 219 passing tests / 947 assertions, with three failures isolated to the Calendar recovery test helper seeding appointment status `held`, which violates the PostgreSQL check constraint (valid initial state is `pending_hold`). Updated the fixture to use `pending_hold`; a fresh full CI run is pending.


## Webhook hardening CI verification (2026-11-23)

- Full GitHub Actions run [45129187315](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45129187315) completed successfully for the webhook hardening code and PostgreSQL-valid Calendar recovery fixture.
- Verified stages: PHP tests on SQLite, SQLite migration rollback/replay, PHP tests on PostgreSQL 16, PostgreSQL migration rollback/replay, Composer/npm production dependency audits, Vite production build, and lazy-loaded page-chunk manifest check.
- This confirms the branch's sequential duplicate-worker/provider-message idempotency tests, late failure callback protection, HTTP 408 ambiguous handling for text and approved-template sends, and Calendar fixture compatibility pass in CI.
- The run tested code commit `635d52f299fc2a1cf4d9f02d6567aa8b9822e46b`; the branch head also includes a subsequent documentation-only update. No real concurrent-worker/process-crash test, live Meta validation, Neon rehearsal, Hostinger validation, or deployment is implied by this CI result.
- Business profile, weekly-hours, booking-policy and staff-exception controllers/tests were also reviewed. Their routes remain behind authenticated, verified tenant context and business-admin role checks; profile and weekly-hours tests verify tenant isolation, while booking-policy and staff-exception tests verify role and foreign-tenant behavior. Remaining cross-tenant route audit continues for any uncovered paths.


## Business configuration tenant-isolation regression follow-up (2026-11-23)

- Added explicit two-workspace route regressions proving a business-profile update cannot overwrite another tenant's profile, weekly-hours updates cannot overwrite another tenant's hours, and booking-policy updates remain scoped to the active tenant.
- These complement the existing profile/hours isolation, staff-role, and staff-exception foreign-tenant tests. The new cases are committed on PR #2 and require a fresh CI run before being marked verified.


## Appointment route isolation follow-up (2026-11-23)

- Extended the cross-tenant appointment regression so a workspace cannot cancel a foreign temporary hold, reschedule a foreign appointment, confirm a foreign appointment through Google Calendar, or cancel a foreign confirmed appointment. Each route must return 404 and leave the foreign appointment unchanged.
- This complements the existing foreign service availability validation and tenant-scoped customer/service/staff validation in hold creation. New assertions are pending CI.


## Webhook crash-boundary clarification (2026-11-23)

- Re-reviewed `ProcessMetaWebhookEvent::handle()`: the `processing` transition, tenant-scoped message/status mutation, and `processed` transition are inside one database transaction. A process crash before commit rolls the transaction back, so the current implementation should leave the event `pending`, not durably `processing`.
- Updated the scheduled stale-`processing` recovery command's comments/purpose to describe it as legacy/orphaned-row cleanup only (for data left by earlier implementations or operational intervention), not as the normal recovery path for current worker crashes.
- Sequential duplicate-worker tests remain useful for idempotency, but they do not simulate real simultaneous PostgreSQL workers or an OS/process crash. Those stronger tests remain an explicit validation gap.


## PR #2 verification update (2026-11-23)

- CI run [45154405291](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45154405291) passed with the appointment cross-tenant route assertions for hold cancellation, rescheduling, Calendar confirmation and confirmed cancellation.
- CI run [45154516958](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45154516958) passed for the webhook crash-boundary clarification checkpoint.
- The business profile, weekly-hours and booking-policy two-workspace regression additions are included in the PR validation history; do not overstate the separate documentation-only checkpoint as a fresh code-suite run.
- PR #2 description now records both successful CI runs and explicitly retains real concurrent PostgreSQL worker/process-crash testing as an open gap.
- No live Meta, Neon, Hostinger or deployment validation has been performed.


## Google Calendar tenant-isolation follow-up (2026-11-23)

- Added a two-workspace regression for the default Google Calendar mapping route. Tenant A updates its own writable calendar and the test asserts Tenant B's stored default calendar remains unchanged.
- Existing coverage already verifies foreign staff-calendar mapping returns 404 without contacting Google.
- The new default-calendar isolation test is committed on PR #2; CI verification is pending.


## Inbox read-path tenant isolation (2026-11-23)

- Added an inbox index regression proving the active workspace list excludes another workspace's conversation.
- Added a conversation detail regression proving foreign conversation IDs return 404 and foreign private notes/messages do not enter the active workspace response.
- Existing tenant-owned global scopes remain the primary enforcement; these tests pin the user-visible read boundary at the HTTP/Inertia layer.
- CI verification is pending.


- Test-fixture review confirmed conversation-note authors are required by the database schema. The foreign-note isolation regression now creates the note under Tenant B with Tenant B's real admin user, rather than a null author; the corrected test commit is awaiting CI.


## Inbox tenant-fixture CI correction (2026-11-23)

- The latest PR-head CI run [45162853608](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45162853608) exposed two failures in the newly added inbox index/detail isolation tests. Both came from the shared test helper inserting the same globally unique Meta `phone_number_id` for multiple workspace connections; 226 other SQLite tests passed (975 assertions) before the suite stopped.
- Updated the helper to generate a distinct phone-number fixture by default, while keeping the known ID only in the existing outbound-reply test whose HTTP fake explicitly matches that endpoint.
- The fixture-only correction is committed as `80a5ac51dd8bd9ba352b9592f249ffdcee5e0aa0`. A new full CI run is required; no pass is claimed until SQLite, PostgreSQL, migrations, dependency audits and frontend build all complete.


## Booking resource tenant-isolation follow-up (2026-11-23)

- Added an HTTP-level regression for appointment hold creation that independently substitutes a foreign workspace's customer ID, service ID and staff ID while keeping the other booking inputs local.
- Each foreign resource must fail validation with 422, and no appointment may be created. This complements the existing cross-tenant availability-service check and cross-tenant appointment mutation checks.
- Test is committed as `76c118ab0af77b260ecdb97b1345a8daa8296043`; fresh full CI is pending.


## Booking resource isolation CI verification (2026-11-23)

- Full CI run [45163875472](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45163875472) passed for code commit `76c118ab0af77b260ecdb97b1345a8daa8296043`.
- The run completed SQLite feature tests, PostgreSQL 16 feature tests, SQLite and PostgreSQL migration rollback/replay, Composer and npm production dependency audits, Vite production build, and route-level lazy chunk verification.
- The booking hold-creation regression independently rejects foreign customer, service and staff identifiers with 422 and confirms no appointment is created.
- This is sequential route-validation evidence only; simultaneous PostgreSQL worker contention/process-crash behavior, live Meta/Google account checks, actual Neon rehearsal, Hostinger runtime validation, and deployment remain unverified.


## Google OAuth workspace-binding regression (2026-11-23)

- Added an HTTP-level test that starts Google OAuth under Workspace A, then attempts to complete the callback under Workspace B while the same authorized user belongs to both workspaces.
- The callback must return 403 before exchanging the authorization code, send no request to Google, and create no connection. This directly exercises the stored tenant binding rather than relying only on an invalid/replayed state token.
- The new regression is committed on PR #2; full CI verification is pending.


## Google OAuth workspace-binding CI verification (2026-11-23)

- Full CI run [45164285714](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45164285714) passed for the OAuth callback workspace-binding regression.
- The test starts authorization under Workspace A, then attempts callback under Workspace B for a user belonging to both. It asserts 403, no outbound Google token-exchange request, and no Calendar connection created.
- CI completed SQLite and PostgreSQL 16 feature suites, migration rollback/replay on both databases, Composer/npm production dependency audits, Vite build, and lazy route-chunk verification.
- This does not substitute for a true simultaneous multi-worker lock/process-crash test or live Google/Meta, Neon, Hostinger and deployment validation.

## Google Calendar disconnect tenant isolation (2026-11-23)

- Added a two-workspace regression that disconnects Google Calendar while Workspace A is active and verifies only Workspace A's refresh/access credentials are revoked and cleared.
- The test also asserts Workspace B remains connected with its original credentials and calendar mapping, and that only one provider revocation request is sent with Workspace A's refresh token.
- The regression is committed on PR #2; full SQLite/PostgreSQL CI verification is pending.
- This is application-level tenant isolation coverage; live Google revocation and production OAuth validation remain separate release gates.

- **CI passed:** [run 45164881189](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45164881189) — two-workspace Google Calendar disconnect regression; SQLite and PostgreSQL 16 feature suites, migration rollback/replay, dependency audits and frontend build all passed.


## Google Calendar credential-list isolation CI verification (2026-11-23)

- Added a two-workspace HTTP regression for the Calendar list endpoint. With Workspace A active, it asserts only A's bearer token is sent to the Google Calendar list API while Workspace B's connection remains separate.
- The initial run [45165189938](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45165189938) exposed a test-fixture assumption: the fake URL hard-coded Google's public API host while the application uses its configured API base URL. The assertion now matches the configured path and verifies the exact Authorization token.
- Corrected full CI run [45165433038](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45165433038) passed.
- This verifies tenant credential selection at the HTTP boundary; it does not establish live Google connectivity or production OAuth readiness.


## Post-merge checkpoint (2026-11-23)

- PR #2 was squash-merged into `main` after its latest full CI run [45240511706](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45240511706) passed.
- Merge commit: `8206662e825e7097d135235b2745892f59dd0405`.
- Included regression coverage for duplicate webhook processing, late failure callbacks, duplicate provider message IDs, ambiguous Meta template-send outcomes, tenant-scoped inbox/booking/Calendar operations, same-user multi-workspace Google credential isolation, and spreadsheet-formula-safe tenant CSV export.
- The CSV export regression specifically verifies formula-leading tenant fields are neutralized before they reach a spreadsheet consumer.
- **Implemented in PR #5; PostgreSQL 16 CI passed:** a dedicated test launches two independent PHP worker processes against the same committed webhook event, uses a test-only trigger to force row-lock contention, and asserts exactly one customer, conversation and inbound message with the event ending in `processed`. [CI run 45243247660](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45243247660) passed. Keep this acceptance gate open until PR #5 is reviewed and merged.
- **Still open:** generate and review `composer.lock` and `package-lock.json` using the manual dependency-lockfile workflow; switch CI to deterministic locked installs only after the generated lockfiles are reviewed and committed.
- Production acceptance gates remain unchanged: actual Neon connection/migration rehearsal, live Meta webhook/send validation, live Google OAuth/Calendar validation, Hostinger runtime/resource checks, backup/restore rehearsal, and deployment rehearsal. No production deployment has been performed.


## Dependency lockfile generation checkpoint (2026-11-23)

- Manual workflow run [45244631505](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45244631505) generated `composer.lock` and `package-lock.json` successfully, and passed Composer validation/audit, npm production audit, clean Composer/npm installs, and the Vite production build.
- The workflow's final `gh pr create` command was blocked by the repository Actions setting that prevents `GITHUB_TOKEN` from creating pull requests. The lockfile commit was nevertheless pushed successfully as `719fa191143e3a01d328815fa5e79429d08e2458` on `chore/dependency-lockfiles-45244631505`.
- Opened reviewable [PR #6](https://github.com/haseeb1984/whatsapp-business-platform/pull/6) separately. Its regular CI run [45245038911](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45245038911) passed.
- Lockfiles are generated and CI-verified on PR #6, but are not yet merged into `main`; do not mark deterministic locked installs as active on main until review and merge.


## Latest verified checkpoint — 2026-11-24

- PR #15, **queue health visibility**, was squash-merged into `main` as commit `44d27235b672ed9f9b5496b859a3f2c86f3fc513`; the `work` branch was fast-forward/synchronized to the same commit.
- The platform operations dashboard now reports the age of the oldest queued job and the number of failed jobs in the previous 24 hours. Recent failure summaries intentionally omit serialized job payloads and exception traces.
- Deployment guidance documents operator-reviewed queue recovery and warns against blind retries of outbound WhatsApp jobs because provider acceptance can be ambiguous.
- Post-merge CI [run 45294585292](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45294585292) passed: SQLite feature suite, SQLite migration rollback/replay, PostgreSQL 16 feature suite, PostgreSQL migration rollback/replay, Composer validation/audit, npm production audit, Vite production build, and lazy-loaded route chunk verification.
- Branch policy checkpoint: only `main` and `work` are intended; both point to the same merge commit and there are no open pull requests at this checkpoint.
- Production has **not** been deployed. Actual Neon connection/TLS and migration rehearsal, Hostinger runtime/cron rehearsal, live Meta and Google acceptance, load/abuse testing, backup/restore drill, and rollback rehearsal remain release gates.
- Queue visibility is observability, not proof of a running worker or a successful external provider delivery. Operators must inspect the underlying failure and provider state before taking recovery action.


## Queue recovery and reproducible CI batch — 2026-11-24

- PR #17 added regression coverage for global pending-outbox dispatch across multiple active tenants, exclusion of suspended tenants, tenant-context cleanup after global scans, and the five-minute dispatch lease that prevents repeated scheduler ticks from flooding the queue.
- The first PR-head CI attempt exposed a test-fixture ordering issue: the suspended tenant was created before tenant-scoped fixtures. The test now creates its fixtures while the tenant is active and suspends it immediately before exercising the global dispatcher.
- Corrected full CI run [45297353400](https://github.com/actions/runs/45297353400) passed all steps: Composer validation/install/audit, SQLite tests and migration rollback/replay, PostgreSQL 16 tests and migration rollback/replay, frontend dependency audit, Vite build, and lazy page-chunk verification.
- PR #17 was squash-merged as `f68c92973115ac6ac1b7aec941210557654f6f4b`; `main` and `work` were synchronized to the same commit, with no open pull requests.
- Follow-up CI hardening changes the frontend dependency install from `npm install` to `npm ci`, ensuring CI uses the committed `package-lock.json` without silently resolving a different dependency tree. The README verification checkpoint now reflects the merged PR #17 and the locked install policy.
- The frontend-install hardening is awaiting its own full CI verification before merge.
- Production acceptance remains a separate release phase: live Neon TLS/connectivity and migration rehearsal, Hostinger PHP/cron/runtime checks, live Meta and Google acceptance, load/abuse testing, browser profiling, backup/restore and rollback rehearsal. No production deployment has been performed.


## Tenant-role authorization matrix expansion — 2026-11-24

- Expanded the staff-denial regression matrix to cover read and mutation attempts against weekly business hours, holiday CRUD, service CRUD, staff CRUD, staff working-hours routes, FAQ read/update/delete, WhatsApp connection update/verify/template-sync/delete, and Calendar disconnect.
- These checks complement existing role-denial coverage for business profile, FAQ creation, WhatsApp connection creation, Calendar OAuth/list/default-calendar/staff-calendar operations, booking policy and lifecycle actions, staff exceptions, and conversation assignment/closure.
- The new route matrix is awaiting full SQLite/PostgreSQL CI verification. Cross-tenant foreign-ID isolation remains a separate requirement and is not considered complete merely because staff role checks pass.


## Production-readiness audit checkpoint — 2026-11-24

- Readiness endpoint review found that it checked only for the existence of the database queue table. It now verifies the required Laravel database-queue columns as well as a successful database connection; an incomplete queue schema returns HTTP 503 without exposing exception details.
- Added a regression test that creates an incomplete `jobs` table and asserts the readiness endpoint reports database healthy but queue storage degraded. Existing healthy, database-failure, and security-header tests remain in place.
- Scheduler coverage already asserts six operational reconciliation/dispatch commands run every minute with overlap prevention. The deployment runbook now explicitly lists the scheduler and bounded worker as separate cron responsibilities.
- PR #19 authorization matrix is marked verified after its passing PR and post-merge CI runs above. README, security notes, deployment notes and this roadmap checkpoint are aligned with the merged state.
- Actual Neon TLS/connectivity/migration, Hostinger PHP/cron/resource limits, live Meta/Google credentials, performance/abuse testing, backup/restore and rollback drills remain external acceptance gates. No production deployment or live-account mutation has been performed.


## Cross-tenant configuration binding and release checklist checkpoint (2026-11-24)

- PR #21 added a business-admin regression that creates foreign-tenant holiday, service, staff, FAQ and WhatsApp connection records, attempts both update and delete through the active tenant, expects HTTP 404 for every request, and verifies the foreign records remain unchanged.
- The first CI attempts exposed test-only fixture defects (PHP namespace escaping, duplicate user email, then duplicate tenant slug); each was corrected before merge. The final PR-head CI [run 45309223904](https://github.com/actions/runs/45309223904) passed all 17 steps across SQLite, PostgreSQL 16, migration rollback/replay, dependency audits and frontend build/chunk verification.
- PR #21 was squash-merged to `main` as `f9fad51ba2218b60c83bd4c1d2cd8387c95735b6`. `work` was force-with-lease synchronized to the same commit. Keep only `main` and `work` as active working branches.
- Added a go-live checklist document to convert remaining external acceptance gates into explicit evidence and operator sign-off steps. Documentation does not claim that live Neon, Hostinger, Meta or Google checks have been performed.
- Still external / access-dependent: real Neon TLS/connectivity and production-like migration rehearsal; Hostinger PHP extensions, cron cadence and bounded worker runtime; live Meta webhook/signature/send/template acceptance; live Google OAuth/Calendar acceptance; load and abuse testing; backup/restore and rollback drills; monitoring and incident handover. Production remains undeployed.


## Integration credential-health visibility (2026-11-24)

- Added read-only platform metrics for WhatsApp pending/non-active connection counts and Google Calendar reauthorization-required, expired-access-token and missing-refresh-token counts.
- The dashboard exposes aggregate counts only; provider tokens, refresh tokens, scopes and account identifiers are not included in the new metrics.
- Added a feature regression with pending WhatsApp and expired/missing-refresh-token Calendar records, asserting the health counts and confirming credential fields are not returned.
- This is database-state visibility, not a live provider permission check or proof that refresh credentials are accepted by Meta/Google. Live authorization checks, usage controls, retention controls and external-account acceptance remain pending.


## AI appointment lookup and reply-race verification — 2026-11-25

- PR #37 added a strict, read-only `list_available_slots` AI tool. It requires an exact active service name and ISO local date, optionally resolves an exact staff name, and reuses the existing tenant-scoped availability service. It cannot create or modify bookings.
- PR #37 passed full CI [run 45427314200](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45427314200), was squash-merged as `dfacfd1e77662430db081f6a01bd75f3352dac1a`, and post-merge CI [run 45538010605](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45538010605) passed. `main` and `work` were synchronized after merge.
- The next verification batch adds deterministic regression tests for two AI response races: staff taking over while the model is generating, and a newer customer message arriving before the generated reply is persisted. The message service already re-locks and validates the conversation state and latest inbound reference at send time; these tests pin that protection.
- Remaining application work includes deeper load/abuse and failure-recovery testing, additional locale/intent coverage, and final calendar/integration UX review. External release gates still require real Neon and Hostinger rehearsals, live Meta and Google acceptance, backup/restore and rollback drills, and production monitoring sign-off. No production deployment has been performed.


## AI safety and Calendar UX checkpoint — 2026-11-25

- PR #38 was merged as `50901fb428915971e26f9530cd9942f1c62933c0`; its full PR-head CI [run 45538838772](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45538838772) and post-merge CI [run 45539007577](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45539007577) passed. Regression tests cover staff takeover and newer-inbound races during AI generation; both prevent a stale automated outbound message from being persisted or sent.
- PR #39 was merged as `5fca6cea717c6d2bb57d7126c2185e10dbc4140d` after full CI [run 45539169208](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45539169208) passed. Calendar settings now display OAuth callback errors, cancel stale calendar-list fetches, validate provider payload shape, and clear stale calendar options when the connected account changes. Post-merge CI [run 45539342104](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45539342104) passed.
- Updated the AI architecture guide to describe the actual six-tool read-only allowlist and distinguish it from the deterministic booking workflow. No model-invoked booking mutation tool is enabled.
- Remaining engineering validation: bounded AI usage/latency telemetry, broader locale and intent coverage, production-like load/abuse and failure-recovery tests, and final UX review. External release gates remain Neon connectivity/migration rehearsal, Hostinger runtime/cron rehearsal, live Meta and Google acceptance, backup/restore and rollback drills, and production monitoring sign-off. No production deployment has been performed.


## Groq operational safeguards — 2026-11-25

- PR #41 added privacy-safe Groq operational telemetry: provider/model, HTTP status, request duration and token counts when available. Prompt text, completions, credentials, customer identifiers and raw provider error bodies are excluded. PR-head CI [run 45539870173](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45539870173) and post-merge CI [run 45540030340](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45540030340) passed; merged as `426439e0faf8e18a3b402764df7352d2ea1a0b70`.
- PR #42 added a configurable shared cooldown after Groq HTTP 429 responses (default 60 seconds, maximum one hour) so tenants sharing the provider credential do not repeatedly hit a rate-limited endpoint. Deterministic answers remain available; unsupported requests safely hand over to staff during cooldown. PR-head CI [run 45540309766](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45540309766) and post-merge CI [run 45540477079](https://github.com/haseeb1984/whatsapp-business-platform/actions/runs/45540477079) passed; merged as `b73d3c1da70834d33c4b7f7546cec68d77dc20d6`.
- Remaining engineering validation: aggregated usage budgets/quotas, provider health dashboard, broader locale/intent coverage, production-like load/abuse and recovery tests, and final UX review. External deployment rehearsals still require actual Neon, Hostinger, Meta and Google account access. No production deployment has been performed.


## Tenant AI usage metering and retention — 2026-11-25

- Added `ai_usage_events` as a metadata-only ledger attributed to the active tenant context. It records provider/model, outcome, HTTP status, latency and provider-reported prompt/completion token counts; it never stores prompts, completions, customer identifiers, credentials or raw provider error bodies.
- Added regression coverage for tenant attribution, cross-tenant isolation, success/rate-limit/cooldown outcomes, platform aggregates and bounded retention cleanup.
- Business admins see only their own workspace's last-30-day AI request/token totals and last-24-hour failed/limited request count. Platform operations sees global totals plus per-tenant 30-day request/token summaries and 24-hour failure/rate-limit counts.
- `ai-usage:prune` deletes old records in batches of 1,000 and is scheduled daily at 02:15. `AI_USAGE_RETENTION_DAYS` defaults to 90 days and is clamped between 30 and 365 days.
- PR #44 merged as `b171c7b7e9b2fcbc418a93e45ad497acd7cc2fa0`; PR-head CI [run 45665338126](https://github.com/actions/runs/45665338126) and post-merge CI [run 45665614271](https://github.com/actions/runs/45665614271) passed. Usage metadata is operational visibility, not a billing record.


## Per-workspace AI daily quota controls — 2026-11-25

- Added an atomic `ai_usage_daily` counter keyed by tenant and date. `AI_TENANT_DAILY_REQUEST_LIMIT` configures a daily provider-request cap for each workspace; zero (default) disables enforcement until an operator selects a suitable plan limit.
- Quota reservation occurs transactionally before the Groq HTTP request, with row locking and bounded deadlock retries. Provider attempts consume a request even if Groq later times out or rejects them; requests skipped during cooldown and quota-blocked attempts do not consume a provider request.
- Provider-reported prompt/completion tokens accumulate in the daily counter. Workspace admins can see today's provider requests, configured cap, and token use; platform operations sees daily totals and quota-block events.
- The daily retention job also prunes daily counters older than 400 days. Event retention remains configurable at 90 days by default.
- PR #45 merged as `a888e8f566fa8f4c48630d6a661d9647d8932ed1`; PR-head CI [run 45667016388](https://github.com/actions/runs/45667016388) and post-merge CI [run 45667272611](https://github.com/actions/runs/45667272611) passed. Token-budget enforcement, alert thresholds, plan-specific quota configuration and abuse/load validation remain open.


## AI provider health indicators — 2026-11-25

- Platform operations now reports seven-day actual provider attempts, success percentage and average provider latency, excluding local cooldown skips and quota-blocked attempts from provider-health denominators.
- The aggregate is based only on metadata-only usage events; it does not expose prompts, completions or customer records. Regression coverage checks the success-rate and latency calculations against mixed successful/rate-limited outcomes.
- PR #46 merged as `9df87e5c5fcec7ea6b201e6d5b48c4a2cb3cb8fa`; PR-head CI [run 45667684378](https://github.com/actions/runs/45667684378) and post-merge CI [run 45667895475](https://github.com/actions/runs/45667895475) passed. No alerting or automatic provider failover is claimed; threshold-based alerts and token-budget enforcement remain open.


## Concurrent AI quota reservation verification — 2026-11-25

- Added a PostgreSQL-only multi-process regression that starts two independent PHP workers simultaneously against the same tenant/day quota of one request.
- A test-only PostgreSQL trigger forces row-lock contention; expected result is exactly one reservation and one blocked attempt, with the durable counter remaining at one. This specifically validates the independent-worker race rather than relying on a single-process test.
- PR #47 merged as `98051dc4246b70be6af1eb8c455987b1355157ff`; PR-head CI [run 45668217231](https://github.com/actions/runs/45668217231) and post-merge CI [run 45668466183](https://github.com/actions/runs/45668466183) passed. The PostgreSQL 16 test ran two independent workers under forced row-lock contention and verified exactly one reservation. Broader production load/abuse testing and live deployment acceptance remain separate release gates.


## Urdu and Roman Urdu intent coverage expansion — 2026-11-25

- Expanded deterministic intent patterns for colloquial Roman Urdu pricing (including `kitnay ki` / `kitne ka`), service-catalog questions, opening-time phrases and public contact-number requests.
- Added Urdu-script variants for service catalog, opening-time and contact-number questions. Answers continue to come only from configured tenant service, hours and business-profile records.
- Added feature regressions for each phrase family. PR #48 merged as `b85a4e3e9a827fb6be2aea3ef501b0237ae26060`; PR-head CI [run 45669090637](https://github.com/actions/runs/45669090637) and post-merge CI [run 45669349263](https://github.com/actions/runs/45669349263) passed.


## Urdu and Roman Urdu deterministic intent coverage — 2026-11-25

- Expanded pricing, service-catalog, business-hours and contact intent patterns for common Urdu and Roman Urdu phrasings, including inflected price questions, plural service questions, opening-time variants and WhatsApp/contact-number questions.
- Responses continue to use only active tenant services and configured business facts; unsupported questions still fall through to grounded FAQ/handover rather than model-invented details.
- Added regression coverage for Roman Urdu weekly hours, including localized weekday labels, closed days and the tenant timezone. Existing tests also cover Urdu pricing/contact, Roman Urdu pricing/service catalog, opening-time and tomorrow-hours variants.
- Broader colloquial coverage, spelling variants and conversational booking workflows remain open; this change does not add booking mutations to the AI tool allowlist.


## Platform operations threshold alerts — 2026-11-25

- Added configurable, read-only dashboard alerts for low seven-day AI provider success rate (with a minimum sample size), elevated average provider latency, repeated rate limits, old queue backlog and repeated failed jobs.
- Thresholds are environment-configurable and clamped to safe ranges when evaluated. The dashboard reports severity, measured value and configured threshold; it does not expose queue payloads, provider prompts, completions or credentials.
- These are dashboard indicators only: no email/SMS paging, automatic retries, provider failover or queue recovery action is performed.
- Regression coverage verifies all five AI/queue warning conditions and confirms that a small provider sample does not produce a misleading success-rate alert.
- PR #49 merged as `042719c96f67acf91d2605c2ea74d425f80eda31`; PR-head CI [run 45670099175](https://github.com/actions/runs/45670099175) and post-merge CI [run 45670316541](https://github.com/actions/runs/45670316541) passed. Token-budget enforcement, alert delivery/escalation, load/abuse testing and external live-account rehearsals remain open.


## Groq Retry-After cooldown handling — 2026-11-25

- The Groq adapter now honors a valid provider `Retry-After` header (seconds or HTTP date) when applying the shared 429 cooldown, bounded to 1–3,600 seconds. Invalid/missing headers fall back to `GROQ_RATE_LIMIT_COOLDOWN_SECONDS`.
- Added a regression that freezes time, verifies a 120-second provider cooldown remains active after 61 seconds, and confirms no second HTTP request is sent.
- PR #49 passed PR-head and post-merge CI as recorded in the platform operations threshold alerts checkpoint above.
