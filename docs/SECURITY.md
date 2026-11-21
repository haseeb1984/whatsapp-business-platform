# Security and Privacy Baseline

## 1. Authentication and authorization

- Use Laravel's supported authentication/session protections. Initial email/password registration, login/logout, password reset and signed email verification are implemented; the current automated CI suite passes.
- Enforce RBAC on every backend action; frontend visibility is not authorization.
- Scope business roles to tenant membership. The request tenant context now carries the verified membership role, and role-restricted middleware protects business profile, weekly hours, holidays, services, staff, staff working hours, FAQs, conversation inbox/handover actions and appointment availability/hold endpoints. Continue applying it to every future module route.
- Protect platform administration with separate privileged permissions and audit logging.
- Rate-limit login, password reset, public endpoints, and sensitive operations.
- Use secure, HTTP-only, SameSite cookies and HTTPS in production.

## 2. Tenant isolation

- Resolve tenant from authenticated membership or verified WhatsApp/Calendar integration ownership. Current web dashboard tenancy is resolved from the authenticated user's active membership; WhatsApp webhook events now resolve a configured active phone_number_id to its tenant; unknown/disabled connections are quarantined. Google Calendar OAuth connections are tenant-scoped and Calendar service calls resolve the connection through the active tenant context.
- Never trust client-supplied tenant identifiers.
- Scope all reads, writes, exports, jobs, and integration calls. A `BelongsToTenant` Eloquent concern now provides a fail-closed tenant global scope, context-derived tenant assignment, tenant ID immutability for model saves, and a custom builder that blocks bulk Eloquent tenant-ID updates for models that opt in. `BusinessProfile`, `BusinessHour`, `BusinessHoliday`, `Service`, `Staff`, `StaffService`, `StaffWorkingHour`, `Faq`, `WhatsAppConnection`, `Customer`, `Conversation` and `Message` currently opt in; `WebhookEvent` is a platform-owned inbox table and intentionally has no tenant global scope. Other tenant-owned models must opt in when introduced. Staff-service assignments and staff working hours use composite tenant-aware foreign keys to their parent records.
- Use composite foreign keys and unique constraints to prevent cross-tenant references.
- Add automated tests proving cross-tenant access is denied. The tenant-owned model contract now has tests for read/update/delete isolation, tenant ID assignment/tampering, and missing-context failure. Request middleware now restores/clears the tenant context in `finally`, and workspace selection/login exclude suspended tenants. A `TenantContext::runForTenant()` helper now provides explicit tenant scoping for background work and restores the previous context; tests cover restoration and suspended-tenant rejection. ResolveTenant also redirects unverified users before tenant resolution and runs before tenant-scoped route model binding. Full production model coverage, tenant-aware foreign keys for cross-entity references and end-to-end queued-job isolation tests are still required. Profile, weekly-hours, holiday, service, staff, staff-working-hours and FAQ feature tests cover tenant separation, cross-tenant service assignment rejection and staff denial, and the current CI suite passes (85 tests / 311 assertions).
- Audit platform staff cross-tenant access.

## 3. Secrets and encryption

- Keep `.env`, Meta tokens, Meta app secret, Groq keys, Google OAuth secrets, and refresh tokens out of Git. WhatsApp and Google Calendar access/refresh tokens use Laravel encrypted casts and are never included in Inertia props. Google OAuth state is bound to the current tenant, user and short expiry window; callback rejects mismatches and replay.
- Encrypt integration credentials at rest using Laravel encryption or a dedicated key-management facility if approved.
- Keep APP_KEY stable and backed up securely; rotation requires a planned re-encryption procedure.
- Never return secret values to the browser.
- Redact Authorization headers, tokens, phone numbers where possible, and message content from logs.
- Use separate credentials for development, staging, and production.

## 4. Webhook security

- Meta webhook POST now verifies HMAC-SHA256 over the raw request body with constant-time comparison, enforces a 1 MiB payload limit, and rejects invalid signatures before parsing.
- Use constant-time signature comparison.
- Apply payload size limits and strict parsing.
- Persist encrypted webhook payloads before acknowledgement and deduplicate using a unique provider/event hash before side effects. Pending events dispatch a retryable database-queue job; it locks the event row and commits customer/conversation/message writes transactionally under the mapped tenant context. Message bodies, metadata, internal conversation notes and appointment event metadata are encrypted at rest. The grounded FAQ assistant retrieves only published FAQs under the active tenant context, validates Groq-selected IDs against that result set, and returns exact approved FAQ answer text rather than model-generated business claims. Outbound text sends require an active verified connection and an inbound message within the 24-hour customer service window. The sender checks `automation_state` (`AUTO_ACTIVE` for automation; `HUMAN_ACTIVE`/`WAITING_FOR_STAFF` for staff) while locking the conversation row through the provider call. Ambiguous timeouts and 5xx responses are recorded as `unknown` and are not blindly retried. Approved template sending and replay/dead-letter operations remain pending.
- Quarantine unknown integration identifiers.
- Do not expose webhook verification secrets in responses or logs.

## 5. AI security

- Treat customer messages and knowledge documents as untrusted input.
- No unrestricted SQL, shell, network, or administrative tools for the model.
- Allowlist tools and validate all arguments server-side.
- Recheck tenant authorization for each tool invocation.
- Never place secrets or unrelated tenant records in model context.
- Validate generated answers against approved facts and operation results.

## 6. Data protection

- Collect only data needed to provide the service.
- Define retention periods for messages, webhook payloads, AI context, audit logs, and customer data.
- Provide a tenant offboarding process covering export, integration revocation, retention, and deletion.
- Restrict access to conversation content and staff notes. Conversation notes are tenant-scoped, encrypted, and only writable by the assigned user while the conversation is in `HUMAN_ACTIVE`.
- Back up database data and test restoration; a backup that has never been restored is not a verified recovery plan.

## 7. Reliability as a security property

- Idempotency for webhooks, outbound actions, bookings, and reminders. Appointment holds use a tenant-unique idempotency key and a tenant-row transaction lock before availability recheck; staff date exceptions and booking policies are tenant-scoped; Google OAuth tokens are encrypted and refreshed server-side; deterministic event IDs support safe lookup after ambiguous creates; ambiguous Calendar outcomes remain reserved pending reconciliation.
- Bounded retries with backoff; no infinite retry loops.
- Failed-job and integration error visibility.
- Database constraints for important invariants.
- Audit sensitive changes to business settings, credentials, staff permissions, and bookings.

## 8. Production launch checklist

- HTTPS enforced and HTTP redirected.
- Debug mode disabled.
- APP_KEY and all credentials unique and securely stored.
- Database TLS verified.
- Database user has least privilege practical for application operation.
- CSRF, session, CORS, and cookie settings reviewed.
- Meta signature verification tested with valid and invalid signatures.
- Tenant isolation tests pass.
- No secrets or sensitive payloads in logs or Git history.
- Backups and restore drill completed.
- Dependency and application security checks completed.
- Incident and credential-rotation procedures documented.
