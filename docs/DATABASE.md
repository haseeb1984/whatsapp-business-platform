# Database Design

## 1. Conventions

- PostgreSQL on Neon is the primary datastore.
- Use UUIDs (or ULIDs) for externally referenced application entities to reduce enumeration risk.
- Every tenant-owned entity includes non-null `tenant_id`.
- Store timestamps in UTC (`timestamptz`); store each tenant's IANA timezone separately for business-hour calculations and display.
- Use decimal/numeric types for money, never floating point.
- Store provider payloads only when needed; redact or minimize personal data and define retention.
- Add created_at/updated_at and, where appropriate, deleted_at.
- Use migrations as the source of truth.

## 2. Core tables

### Identity and tenancy
- `tenants`: id, name, slug, status, timezone, locale, created_at.
- `users`: id, name, email, password_hash, status, last_login_at.
- `tenant_user`: tenant_id, user_id, role_id, status; unique tenant/user membership.
- `roles`, `permissions`, `role_permissions`: role and permission definitions. Platform roles must be distinct from tenant roles.
- `tenant_settings`: tenant_id, key, JSONB value; constrain keys and validate values in application code.

### Business configuration
- `business_profiles`: tenant_id, display_name, address, contact details, description, policies.
- `business_hours`: tenant_id, weekday, opens_at, closes_at, closed.
- `business_holidays`: tenant_id, starts_at, ends_at, name.
- `services`: tenant_id, name, description, price_amount, currency, duration_minutes, buffer_before_minutes, buffer_after_minutes, active.
- `staff`: tenant_id, name, contact, active.
- `staff_services`: tenant_id, staff_id, service_id.
- `staff_working_hours`: tenant_id, staff_id, weekday, opens_at, closes_at.
- `availability_exceptions`: tenant_id, staff_id nullable, starts_at, ends_at, type, reason.

### WhatsApp and conversations
- `whatsapp_connections`: tenant_id, phone_number_id, waba_id, business_account_id, encrypted_access_token, token_expires_at, status, verified_at.
- `whatsapp_templates`: tenant_id, provider_template_name, language, category, approval_status, components JSONB.
- `webhook_events`: tenant_id nullable until resolved, provider, provider_event_key, event_type, payload JSONB (minimized), received_at, processed_at, status, attempts; unique provider/event key.
- `customers`: tenant_id, whatsapp_wa_id, display_name, profile metadata; unique tenant/wa_id.
- `conversations`: tenant_id, customer_id, status, automation_state, assigned_user_id, last_message_at.
- `messages`: tenant_id, conversation_id, direction, message_type, body, provider_message_id, delivery_status, metadata JSONB, sent_at; unique provider message ID within provider/tenant.
- `conversation_assignments`: tenant_id, conversation_id, user_id, assigned_at, released_at.
- `conversation_notes`: tenant_id, conversation_id, user_id, body.

### Knowledge and AI
- `faqs`: tenant_id, question, answer, keywords JSONB, status, locale, updated_by.
- `knowledge_documents`: tenant_id, title, content, source_type, status, version.
- `ai_provider_configs`: tenant_id nullable for platform default, provider, model, encrypted credential reference, enabled, priority, settings JSONB. Never expose secrets to the browser.
- `ai_usage_records`: tenant_id, provider, model, input_tokens, output_tokens, latency_ms, status, created_at.
- `conversation_contexts`: tenant_id, conversation_id, context JSONB, expires_at.

### Calendar and appointments
- `calendar_connections`: tenant_id, provider, google_account_email, encrypted_access_token, encrypted_refresh_token, token_expiry, status.
- `staff_calendars`: tenant_id, staff_id, calendar_connection_id, external_calendar_id, active.
- `appointments`: tenant_id, customer_id, service_id, staff_id nullable, calendar_connection_id nullable, starts_at, ends_at, status, source, idempotency_key, external_event_id, calendar_sync_status, hold_expires_at.
- `appointment_events`: tenant_id, appointment_id, event_type, actor_id nullable, metadata JSONB, created_at.
- `appointment_reminders`: tenant_id, appointment_id, channel, scheduled_at, status, sent_at, idempotency_key.

### Platform operations
- `jobs`, `job_batches`, `failed_jobs`: Laravel database queue tables.
- `audit_logs`: tenant_id nullable for platform events, actor_id, action, subject_type, subject_id, metadata JSONB, created_at.
- `integration_logs`: tenant_id, integration, operation, correlation_id, status, sanitized_error, latency_ms.
- `usage_records`: tenant_id, metric, quantity, period_start, period_end.

## 3. Tenant-owned Eloquent model contract

Use `App\Models\Concerns\BelongsToTenant` on every tenant-owned Eloquent model. The concern adds a fail-closed global tenant scope, assigns `tenant_id` from the active `TenantContext` on create, rejects a caller-supplied tenant mismatch, and prevents moving an existing model between tenants, and uses a custom Eloquent builder to reject bulk `update()` attempts that modify `tenant_id`. Avoid raw query-builder updates, `upsert()`, and direct DB access for tenant-owned tables; these bypass model-level protections. It deliberately throws when a tenant-owned query runs without a context; background jobs and command handlers must explicitly establish a tenant context for each unit of work. `TenantContext::runForTenant()` reloads an active tenant, binds a system context for the callback, rejects suspended tenants, and restores the prior context in a `finally` block. Do not use `withoutGlobalScope(s)` in tenant-facing code. This is an application guardrail, not a replacement for tenant-aware foreign keys and database constraints.

## 4. Critical constraints and indexes

- Unique `tenants.slug`.
- Unique `tenant_user(tenant_id, user_id)`.
- Unique `customers(tenant_id, whatsapp_wa_id)`.
- Unique `webhook_events(provider, provider_event_key)` (or include account identifier if provider key scope requires it).
- Unique `appointments(tenant_id, idempotency_key)` when idempotency key is present.
- Unique `messages(tenant_id, provider_message_id)` when provider ID is present.
- Index tenant-owned tables by `(tenant_id, created_at)` or their main lookup pattern.
- Index conversations by `(tenant_id, status, last_message_at)`.
- Index appointments by `(tenant_id, starts_at, status)` and `(tenant_id, staff_id, starts_at)`.
- Add composite foreign keys such as `(tenant_id, customer_id)` referencing `customers(tenant_id, id)` to prevent cross-tenant references at database level.
- Add exclusion constraints for overlapping confirmed appointments per staff/resource where feasible, using PostgreSQL range types and `btree_gist`; also enforce service buffers in availability logic. If extension availability is restricted, use transactional advisory locks or row locks with a deterministic resource key.

## 5. Appointment overlap model

A booking must reserve a staff/resource time range atomically. Candidate availability is advisory until a transaction rechecks it. Within a transaction:
1. Acquire a lock for tenant + staff/resource + relevant time window.
2. Recheck overlapping active appointments and local exceptions.
3. Insert a short-lived pending hold with an idempotency key.
4. Commit the hold before the external calendar call.
5. Create the external event using a stable operation identifier.
6. Persist the external event ID and transition to confirmed.
7. On failure, mark failed/release hold and run reconciliation if the external outcome is ambiguous.

The database constraint is the final guard where supported; application checks alone are insufficient under concurrent requests.

## 6. JSONB guidance

Use JSONB for provider payload fragments, flexible settings, and audit metadata—not as a substitute for relational columns used in joins, authorization, uniqueness, or scheduling. Validate JSON shapes and size limits.

## 7. Data lifecycle

Define retention and deletion policies for message bodies, webhook payloads, AI context, audit logs, and OAuth tokens before production. Tenant offboarding must revoke integrations, stop scheduled jobs, and provide an auditable export/deletion workflow consistent with applicable legal obligations.
