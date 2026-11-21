# Appointment Booking and Google Calendar

## 1. Booking inputs

A booking request may require service, preferred date, preferred time range, staff preference (optional), customer identity, and timezone. Ask one focused question at a time when information is missing.

## 2. Availability calculation

Candidate slots are calculated from:
- tenant business hours and timezone;
- business holidays and special closures;
- staff working hours and staff-service eligibility;
- service duration and pre/post buffers;
- staff exceptions and leave;
- active local appointments and temporary holds;
- Google Calendar FreeBusy periods;
- minimum notice and booking horizon configured by the business.

Convert local business times to UTC for storage and API operations. Handle daylight-saving transitions using IANA timezone identifiers.

## 3. Safe booking lifecycle

Suggested states:

- `draft`: collecting details.
- `availability_checked`: candidate slot shown; not reserved.
- `pending_hold`: short-lived local hold acquired.
- `calendar_pending`: external event operation underway or outcome ambiguous.
- `confirmed`: external event ID stored and local transaction committed.
- `reschedule_pending`: change is in progress.
- `cancel_pending`: external cancellation in progress.
- `cancelled`: cancellation completed and persisted.
- `failed` / `expired`: operation failed or hold expired; not confirmed.

Only `confirmed` may be communicated as a confirmed booking.

## 4. Concurrency and double-booking prevention

Availability shown to a customer can become stale. Recheck immediately after the customer selects a slot. Use a transaction plus resource/time locking and database constraints where supported. A unique idempotency key prevents duplicate booking creation from webhook retries or customer double taps.

For shared-hosting deployment, database locking must be short-lived; never hold a PostgreSQL transaction open while waiting for Google API network calls.

## 5. Google Calendar connection

- OAuth authorization uses validated state, exact redirect URI, and least-privilege scopes.
- Encrypt access and refresh tokens at rest.
- Support a tenant connection and multiple staff calendar mappings.
- Refresh access tokens safely and handle revoked/expired authorization.
- Use FreeBusy for availability and event create/update/delete for appointment lifecycle.
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
- For reschedule, preserve the old confirmed event until the replacement operation is safely committed, or use a clearly documented update strategy.
- For cancellation, do not mark cancelled until the calendar deletion/update outcome is known; handle already-deleted events idempotently.

## 7. Reminders

- Store scheduled reminder rows with unique idempotency keys.
- Cron processes due reminders in bounded batches.
- Recheck appointment status immediately before sending.
- Use permitted WhatsApp templates when outside the customer service window or otherwise required by Meta policy.
- Cancel pending reminders on reschedule/cancellation and schedule replacements.
- Track attempts, outcomes, and provider message IDs.

## 8. Acceptance tests

- Two customers cannot reserve the same staff/resource slot concurrently.
- A webhook retry does not create a second appointment or calendar event.
- No confirmation is sent when Google Calendar fails.
- Ambiguous Calendar timeout is reconciled before creating another event.
- Reschedule updates the correct event and reminder schedule.
- Cancellation stops future reminders.
- Timezone and daylight-saving boundaries produce correct slots.
- Tenant A cannot inspect or mutate Tenant B's calendars, staff, or appointments.
