# WhatsApp Cloud API Integration

## 1. Supported integration

Use Meta's official WhatsApp Business Platform Cloud API. No WhatsApp Web, QR-login session, browser automation, unofficial library, or reverse-engineered endpoint is permitted.

Start development with a Meta test business/phone number. Production tenant onboarding and Embedded Signup are a later milestone after the core integration is stable.

## 2. Tenant connection mapping

Persist the verified relationship between tenant, WABA/business account, and phone_number_id. Incoming webhook events are routed to a tenant only by matching a known verified integration identifier. If no unique active mapping exists, quarantine the event and alert platform operations; never guess a tenant.

Access tokens and app secrets must be encrypted at rest and never returned to dashboard clients or written to logs.

## 3. Webhook verification

### GET subscription verification
- Read Meta's verification mode, verify token, and challenge parameters.
- Compare mode and configured verify token using constant-time comparison where practical.
- Return the challenge only when verification succeeds.
- Do not expose secrets in error messages.

### POST event validation
- Preserve the raw request body.
- Validate `X-Hub-Signature-256` using HMAC-SHA256 and the configured Meta app secret.
- Use constant-time comparison.
- Reject invalid signatures before parsing or persisting business actions.
- Validate payload shape and size; tolerate unknown event fields for forward compatibility.

## 4. Inbound event pipeline

1. Validate signature and basic payload shape.
2. Extract stable provider event/message identifiers and integration routing identifiers.
3. Insert the event into `webhook_events` with a unique idempotency key.
4. If already present, treat it as a duplicate and do not repeat side effects.
5. Commit durable storage.
6. Return a successful acknowledgement promptly.
7. Process the event asynchronously through a bounded database-queue job.
8. Persist normalized message/status records and update conversation state.
9. Recheck tenant status and human handover before generating/sending any automated response.

Do not call Groq, Google Calendar, or other slow remote services before acknowledging a webhook.

## 5. Outbound messaging

- Route every outbound request through a single Meta adapter.
- Validate recipient, message type, template approval, locale, and policy constraints before send.
- Store an outbound attempt before the network call.
- Use a stable internal idempotency/correlation key.
- Persist Meta's message ID and response status.
- Process delivery-status webhooks to update sent/delivered/read/failed states.
- Retry only errors classified as transient. Avoid blindly retrying ambiguous timeouts that may have resulted in a successful provider send.
- Respect Meta's current messaging window, template, quality, and opt-in rules; verify policy details during implementation against current official documentation.

## 6. Human handover safety

Before every automated outbound send, reload/verify conversation automation state. If state is HUMAN_ACTIVE or WAITING_FOR_STAFF, suppress the send and retain the work item for staff or discard it according to the workflow. A queued AI response must not leak after staff has taken over.

## 7. Operational handling

- Maintain a dead-letter/failed-event view.
- Record correlation IDs and sanitized error details.
- Provide safe replay for idempotent event handlers.
- Never replay a side effect without checking its idempotency record.
- Alert on signature failures, unmapped phone IDs, persistent webhook errors, and queue backlog.
- Keep webhook endpoint independent from dashboard authentication; secure it through Meta signature validation and strict request validation.
