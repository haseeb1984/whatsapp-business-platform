# Product and Technical Requirements

## 1. Purpose

Build a configurable SaaS-style WhatsApp Business automation platform. A single application and codebase must support many businesses with logically isolated data and configurable workflows.

## 2. Actors and roles

- **Platform owner / super administrator:** manages tenants, platform configuration, operational health, usage, and support access.
- **Business owner / administrator:** manages their business profile, WhatsApp connection, staff, services, FAQs, hours, integrations, users, and automation settings.
- **Business staff / agent:** views assigned conversations and customers, replies to customers, and takes/releases human handover.
- **Customer:** communicates with a business through WhatsApp; no platform login is required for the initial release.
- **Scheduled processor:** internal system actor that processes queued events, reminders, retries, and reconciliation tasks.

Role permissions must be explicit and tenant-scoped. Platform administration must not be exposed through ordinary business roles.

## 3. Functional requirements

### FR-01 Multi-tenancy
- A tenant represents one business account.
- Business-owned records must carry a tenant identifier and be scoped on the server.
- Tenant context must be resolved from authenticated membership or a verified integration mapping, never from an untrusted request parameter alone.
- A business user must not read or mutate another tenant's data by changing an ID.
- Platform administrators may access cross-tenant operational views under audited privileged permissions.
- A single deployment and codebase serves all tenants.

### FR-02 Business configuration
Each tenant can configure business name, contact details, timezone, locale, address, opening hours, holidays, policies, supported services, prices, service durations, buffers, staff, staff-service eligibility, and automation preferences.

### FR-03 WhatsApp Cloud API
- Connect through official Meta WhatsApp Business Cloud API only.
- Support webhook subscription verification and signed webhook event validation.
- Receive messages, statuses, and relevant account events.
- Send supported text and approved template messages.
- Persist inbound events and outbound message records.
- Deduplicate Meta webhook retries and outbound retry attempts.
- Track accepted, sent, delivered, read, and failed states where provided by Meta.
- Support test credentials and a controlled onboarding process before production Embedded Signup.
- Never use WhatsApp Web, browser automation, QR-session libraries, or unofficial endpoints.

### FR-04 Conversations and customers
- Create or resolve a tenant-scoped customer using the WhatsApp contact identifier.
- Maintain conversations and message history.
- Support assignment, status, labels, notes, and search.
- Preserve provider message IDs and delivery status.
- Enforce tenant scope for every customer/conversation/message operation.

### FR-05 FAQ and knowledge
- Allow business admins to create, edit, publish, and archive approved FAQs and business knowledge.
- Prefer exact/structured answers over generative inference.
- AI answers must be grounded in the active tenant's approved content.
- If the answer is absent, stale, ambiguous, or conflicting, ask a clarifying question or hand over to staff.
- Never invent prices, availability, policies, opening hours, or service details.
- Record the source(s) used for an answer for audit/debugging.

### FR-06 AI orchestration
- Groq is the initial AI provider.
- Use an application-owned provider interface; business logic must not depend directly on a Groq SDK response shape.
- Separate intent detection, retrieval, response generation, validation, and tool execution.
- Limit model context to the current tenant and relevant conversation data.
- Treat customer messages and retrieved documents as untrusted input.
- Use strict allowlisted tools with validated arguments and tenant authorization.
- Handle rate limits, timeouts, provider outages, malformed responses, and token limits.
- On AI failure, use deterministic FAQ/flow responses or human escalation; do not fabricate a response.

### FR-07 Human handover
- Staff can take over a conversation.
- While human-active, automated AI and scheduled bot replies must be blocked.
- Staff release explicitly resumes automation.
- Handover state must be checked immediately before sending any automated response, not only when a job is queued.
- Record actor, timestamp, and reason for handover/release.

### FR-08 Appointments
- Support service selection, preferred staff (optional), date, timezone, and available time slots.
- Availability is derived from business hours, holidays, staff schedules, service duration, buffers, exceptions, existing appointments, and calendar busy periods.
- Recheck availability immediately before committing a booking.
- Prevent duplicate processing and double booking using database constraints/locking and idempotency keys.
- Support booking, reschedule, cancellation, status changes, confirmations, and reminders.
- Never claim a booking succeeded unless all required persistence and calendar steps succeeded.
- Define and test behavior when Google Calendar is unavailable or partially succeeds.

### FR-09 Google Calendar
- Support per-tenant calendar connections and multiple staff calendars.
- Use OAuth with validated state and securely stored refresh tokens.
- Query free/busy data and create/update/delete events as authorized.
- Store external event IDs and sync status.
- Reconcile local appointments with calendar events after transient failures.
- Do not assume a local database transaction can atomically include a Google API operation.

### FR-10 Dashboards and administration
- Business dashboard: conversations, leads/customers, appointments, staff, FAQs, services, integrations, usage, and settings.
- Platform dashboard: tenant status, integration health, queue backlog, failed jobs, usage, and audit events.
- Enforce role-based authorization at the backend, not just by hiding UI controls.

### FR-11 Notifications and usage
- Send booking confirmations and reminders using permitted WhatsApp message types/templates and applicable Meta policy/window rules.
- Track AI usage, messages, bookings, and tenant-level quotas.
- Make reminder scheduling idempotent and cancellation-aware.

## 4. Non-functional requirements

- **Security:** encrypted secrets, least privilege, CSRF protection, secure sessions, signature verification, rate limiting, audit logging, and data minimization.
- **Isolation:** tenant scoping and negative cross-tenant authorization tests for all business-owned resources.
- **Reliability:** durable event ingestion, retries with backoff, idempotency, failed-job visibility, and reconciliation.
- **Performance:** webhook acknowledgement must be fast and independent of slow AI/calendar calls. Batch sizes and cron frequency must respect shared-hosting limits.
- **Maintainability:** modular monolith, clear interfaces, automated tests, migrations, and accurate docs.
- **Portability:** avoid reliance on Hostinger-specific runtime behavior in domain logic; allow future worker migration without rewriting workflows.
- **Observability:** structured logs with correlation IDs, health checks, queue metrics, integration status, and redaction of secrets/PII.
- **Accessibility and usability:** responsive dashboards and clear staff handover state.

## 5. Out of scope for initial release

- Unofficial WhatsApp Web automation.
- Separate codebases or deployments per business.
- Autonomous AI actions outside explicitly allowlisted workflows.
- Guaranteed instant background processing on shared hosting.
- Voice calling, payment collection, ecommerce order fulfillment, and arbitrary third-party CRM integrations unless separately approved and specified.
- White-label/reseller licensing unless separately defined.

## 6. Acceptance principles

A feature is complete only when its implementation, authorization, failure handling, automated tests, and documentation are present. A UI mockup or successful happy-path demo alone is not completion. Planned work must be labelled as planned.
