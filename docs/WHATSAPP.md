# WhatsApp Cloud API Integration

## 1. Supported integration

Use Meta's official WhatsApp Business Platform Cloud API. No WhatsApp Web, QR-login session, browser automation, unofficial library, or reverse-engineered endpoint is permitted.

Start development with a Meta test business/phone number. The first setup screen stores a tenant's Phone Number ID, WABA ID and access token encrypted at rest. A Meta Graph API verification action checks the configured phone number and confirms it appears under the configured WABA before setting the connection active. Embedded Signup is not implemented yet.

## 2. Tenant connection mapping

Persist the verified relationship between tenant, WABA/business account, and phone_number_id. Incoming webhook events are routed to a tenant only by matching a known active, Meta-verified phone_number_id. If no unique active mapping exists, quarantine the event and alert platform operations; never guess a tenant.

Access tokens are encrypted at rest and omitted from dashboard props. App secrets remain environment configuration. Neither is returned to dashboard clients or written to logs.

## 3. Webhook verification

### GET subscription verification
- Implemented at `/api/webhooks/meta/whatsapp`.
- Read Meta's verification mode, verify token, and challenge parameters.
- Compare mode and configured verify token using constant-time comparison where practical.
- Return the challenge only when verification succeeds.
- Do not expose secrets in error messages.

### POST event validation
- Implemented raw-body HMAC-SHA256 validation with constant-time signature comparison and a 1 MiB request limit.
- Preserve the raw request body.
- Validate `X-Hub-Signature-256` using HMAC-SHA256 and the configured Meta app secret.
- Use constant-time comparison.
- Reject invalid signatures before parsing or persisting business actions.
- Validate payload shape and size; tolerate unknown event fields for forward compatibility.

## 4. Inbound event pipeline

1. Validate signature and basic payload shape (implemented).
2. Extract stable provider event/message identifiers and integration routing identifiers.
3. Insert the event into `webhook_events` with a unique SHA-256 idempotency key and encrypted payload (implemented).
4. If already present, do not create another event row; if it is still pending, safely re-dispatch its job so a prior queue-dispatch interruption can recover (implemented).
5. Commit durable storage before acknowledgement (implemented).
6. Return a successful acknowledgement promptly (implemented).
7. Dispatch `ProcessMetaWebhookEvent` after commit; it runs with five attempts and bounded backoff, uses a row lock plus a transaction, restores tenant context, and marks the event processed only after normalized writes commit.
8. Persist normalized customer, conversation and encrypted message/status records; duplicate provider message IDs do not create duplicate messages (implemented).
9. Recheck tenant status and human handover before generating/sending any automated response (human handover gate remains pending).

Do not call Groq, Google Calendar, or other slow remote services before acknowledging a webhook.

## 5. Outbound messaging

- Implemented: text-only outbound sends use the official Graph API adapter and a tenant-active connection.
- Enforce the 24-hour customer service window from the latest inbound message; outside the window, free-form text is blocked and an approved template is required. See [Meta WhatsApp Business Policy](https://whatsappbusiness.com/policy/).
- Automation sends are allowed only in `AUTO_ACTIVE`; staff sends require `HUMAN_ACTIVE` or `WAITING_FOR_STAFF`.
- The sender locks the conversation row through the bounded provider request so a concurrent handover transition cannot overtake an already-authorized send.
- Ambiguous timeouts/5xx outcomes are stored as `unknown`; never blindly retry them.
- Approved template sending is not implemented yet.
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
