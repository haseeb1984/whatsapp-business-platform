# Appointment Booking and Google Calendar

## 1. Booking inputs

A booking request may require service, preferred date, preferred time range, staff preference (optional), customer identity, and timezone. Ask one focused question at a time when information is missing.

## 2. Availability calculation

**Implemented local availability:** candidate slots use tenant business hours/timezone, business holiday closure or special-hours overrides, staff weekly working hours and date-specific exceptions, active staff/service assignments, service duration, pre/post buffers, tenant minimum-notice/booking-horizon/slot-interval policies, and local appointment holds. Slots are generated on a bounded interval (15 minutes by default) and returned with UTC ISO timestamps plus the local UTC offset. Past local dates and invalid/nonexistent local wall-clock schedule times produce no slots.

Staff date-specific leave/special-hours overrides and tenant policies for minimum notice, booking horizon, slot interval and hold duration are now implemented and editable by business admins.

**Implemented:** when a tenant has connected Google Calendar, availability queries each eligible staff calendar through FreeBusy and excludes overlapping external busy periods. If the external calendar cannot be checked, availability fails closed instead of returning unverified slots. A final FreeBusy check runs before event creation.

Convert local business times to UTC for storage and API operations. Slot results carry explicit UTC offsets so repeated daylight-saving wall times remain distinguishable; the hold endpoint accepts the selected ISO-8601 instant, not an ambiguous local string. Use IANA timezone identifiers.

## 3. Safe booking lifecycle

The initial local lifecycle and temporary-hold transitions are implemented. Suggested states:

- `draft`: collecting details.
- `availability_checked`: candidate slot shown; not reserved.
- `pending_hold`: short-lived local hold acquired.
- `calendar_pending`: external event operation underway or outcome ambiguous.
- `confirmed`: external event ID stored and local transaction committed.
- `reschedule_pending`: change is in progress.
- `cancel_pending`: external cancellation in progress.
- `cancelled`: cancellation completed and persisted.
- `failed` / `expired`: operation failed or hold expired; not confirmed.

Only `confirmed` may be communicated as a confirmed booking. The application creates `pending_hold` records, transitions to `calendar_pending`, checks FreeBusy, creates a deterministic Google event and calls the guarded confirmation transition with its event ID. The business dashboard exposes confirm/reconcile and cancel actions. The customer-facing WhatsApp booking conversation is still pending.

## 4. Concurrency and double-booking prevention

Availability shown to a customer can become stale. Recheck immediately after the customer selects a slot. Use the transaction and tenant-row lock in `AppointmentBookingService`, recheck the selected slot inside the transaction, and enforce unique `(tenant_id, idempotency_key)`. The tenant-level lock serializes hold creation for that workspace; consider finer-grained staff/time locks if measured throughput requires it.

The current hold service locks the tenant row, rechecks availability, and writes the hold in a short transaction. The unique `(tenant_id, idempotency_key)` constraint prevents webhook retries from creating duplicate holds. Active pending holds expire after the tenant-configured hold duration (10 minutes by default); the bounded `appointments:expire-holds` command runs every minute through Laravel scheduling. Calendar API calls must happen after the local transaction commits—never hold PostgreSQL locks while waiting for Google.

A `calendar_pending` appointment blocks the slot. If the Calendar outcome is ambiguous, mark `calendar_sync_status=unknown` but keep the appointment in `calendar_pending` until reconciliation determines whether the external event exists. Only a definite failure may release the slot.

## 5. Google Calendar connection

**Implemented:** OAuth state + PKCE validation bound to the signed-in user and active tenant, verified Google account identity, required-scope checks, encrypted access/refresh tokens, access-token refresh, token revocation on disconnect, writable calendar listing, tenant default calendar selection and per-staff calendar mapping. FreeBusy is integrated into availability and is checked again before event creation. Appointment event IDs are deterministic; retries look up an existing event before attempting another create. Cancellation calls Google event deletion and only then marks the appointment cancelled. Rate limits and ambiguous create/delete outcomes keep the appointment reserved for reconciliation.

**Still pending:** event update/reschedule, automated reconciliation worker for unknown outcomes, reminders, and live acceptance testing against a dedicated Google test account.

- Use exact configured redirect URI and least-privilege scopes.
- Store calendar ID, external event ID, ETag/version if available, sync state, and last sync time.
- Do not expose OAuth tokens to the frontend.

## 6. Cross-system consistency

PostgreSQL and Google Calendar do not share a transaction. Use an explicit saga/reconciliation approach:
- Create a pending local hold.
- Call Google Calendar with a stable operation marker.
- Persist event ID and confirm locally.
- If the response times out, query/reconcile by stable marker before retrying create.
- If the event exists but local persistence failed, attach the event to the pending appointment.
- If event creation definitely failed, release/expire the hold and communicate that the slot was not booked.
- Rescheduling is not implemented yet. When added, preserve the old confirmed event until the replacement operation is safely committed, or use a clearly documented update strategy.
- For cancellation, do not mark cancelled until the calendar deletion/update outcome is known; handle already-deleted events idempotently.

## 7. Reminders

**Implemented:** confirmed appointments create idempotent 24-hour and 2-hour reminder rows when those offsets are still in the future. A bounded `appointments:dispatch-reminders` scheduler queues due work; jobs recheck appointment status, customer-service window and automation/human handover. Reminder sends use a unique message correlation key and lock appointment/reminder state before sending, preventing duplicate sends and cancellation races. Appointment cancellation cancels pending and blocked reminders. Delivery status, attempts and provider message IDs are recorded and shown in the appointment dashboard.

**Policy limitation:** reminders are sent as free-form text only when the 24-hour customer-service window is open and automation is active. Outside that window, reminders are held as `blocked_template_required`; approved WhatsApp template sending is not implemented yet, so those reminders are not sent until template support is added.

**Still pending:** reminder templates, reminder retry/reconciliation UI, locale-specific reminder content and full end-to-end delivery-status reconciliation.

## 8. Acceptance tests

- Two customers cannot reserve the same staff/resource slot concurrently.
- A webhook retry does not create a second appointment or calendar event.
- No confirmation is sent when Google Calendar fails.
- Ambiguous Calendar timeout is reconciled before creating another event.
- Reschedule updates the correct event and reminder schedule.
- Cancellation stops future reminders.
- Timezone and daylight-saving boundaries produce correct slots.
- Tenant A cannot inspect or mutate Tenant B's calendars, staff, or appointments.
