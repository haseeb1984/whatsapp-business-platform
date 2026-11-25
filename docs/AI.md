# AI and FAQ Architecture

## 1. Provider strategy

Groq is the configured initial provider. Application services depend on AiProviderInterface, not on Groq-specific SDK classes. GroqProvider uses Groq's OpenAI-compatible chat-completions endpoint with a configured model, base URL, timeout, zero temperature and bounded output tokens.

Configuration is server-side through GROQ_API_KEY, GROQ_MODEL, GROQ_BASE_URL and GROQ_TIMEOUT. Do not store provider credentials in tenant records, return them to the browser, or log them. No secondary paid provider is enabled.

The current interface exposes bounded generate(messages, options). Groq requests emit structured operational logs with provider/model, HTTP status, elapsed milliseconds and provider-reported token counts when available. A tenant-attributed `ai_usage_events` ledger stores provider/model, outcome, HTTP status, latency and token counts only; it never stores prompts, completions, customer identifiers, raw provider error bodies or credentials. Business admins see their own workspace's 30-day AI attempt/token totals, today's provider requests/tokens and 24-hour failures; the platform operations dashboard shows global and per-tenant aggregates plus seven-day provider attempt, success-rate and average-latency indicators. Events are pruned daily in bounded batches with a configurable 90-day default retention (clamped to 30–365 days); daily quota counters are retained for 400 days. `AI_TENANT_DAILY_REQUEST_LIMIT` enables an atomic per-tenant daily provider-request cap; zero (the default) disables enforcement. A configured cap is reserved transactionally before contacting Groq, so concurrent workers cannot exceed the same tenant/day counter. Provider attempts count against the cap even if the provider later times out or rejects the request; cooldown skips and quota-blocked attempts do not consume a provider request. Provider-reported tokens are accumulated separately. These are operational usage measurements, not billing records. Token-budget enforcement, alert thresholds, provider selection and a secondary provider remain future work.

## 2. Implemented grounded FAQ path

FaqRetrievalService:
- reads only the current tenant's published FAQ rows;
- respects the requested locale and falls back from a regional locale such as en-US to its base locale;
- uses a bounded keyword-overlap ranking and returns at most five candidates.

GroundedFaqAssistant:
1. returns an exact approved FAQ answer directly when the lexical match is sufficiently strong;
2. otherwise asks Groq to select candidate FAQ IDs only;
3. validates every selected ID against the retrieved candidate set;
4. returns the original approved FAQ answer text, never model-generated answer prose;
5. returns handoff when there is no evidence, Groq explicitly requests handoff, IDs are invalid, or a deterministic fallback is not sufficiently confident.

If Groq is unavailable or rate-limited, only a high-confidence deterministic FAQ match can be returned. Otherwise the result is handoff. The model is a selector, not a source of business facts.

## 3. Automated inbound processing

Inbound text messages enqueue one tenant-scoped reply job after the webhook transaction commits. The job checks that the conversation is open, automation is `AUTO_ACTIVE`, and the triggering message is still the latest inbound message before invoking the assistants.

The response order is:
1. Deterministic business-intent routing for configured service catalog/pricing, weekly/today/tomorrow hours, and public contact details in English, Urdu and Roman Urdu.
2. Grounded FAQ retrieval and approved-answer selection from the current tenant's published FAQs.
3. A bounded Groq tool selector, invoked only when the earlier paths cannot answer.

If no approved evidence or supported tool result is available, the conversation moves to `WAITING_FOR_STAFF` without inventing a response. If a grounded answer or read-only tool result is available, it is sent through the official WhatsApp adapter.

Automated outbound messages store a same-tenant `reply_to_message_id` with a unique constraint. Reprocessing the same job returns the existing outbound attempt instead of sending another message. At send time, the message service locks the conversation again and verifies that automation is still active and the triggering inbound message remains the latest. This blocks replies made stale by staff takeover or a newer customer message while Groq is generating.

## 4. Grounding and refusal behavior

The assistant may answer only from approved tenant knowledge and live system results. It must not infer or invent:
- prices, discounts, taxes, or payment policies;
- opening hours, holidays, or location details;
- staff identity or availability;
- appointment availability or booking success;
- cancellation/refund rules;
- stock or service availability.

When required information is missing or conflicting, return handoff. A model confidence score alone is not proof that a fact is correct.

## 5. Current tool allowlist

The model can select at most one tool from this explicit read-only allowlist:

- `list_services` — list active services with configured prices and durations.
- `search_services` — find active services using a bounded text query.
- `get_business_hours` — return configured hours for today, tomorrow, or the regular week.
- `get_business_contact` — return configured public contact details only.
- `get_booking_policy` — return the configured booking notice, horizon and slot interval.
- `list_available_slots` — read appointment availability for an exact active service name and ISO local date, optionally restricted to an exact staff name.

The application validates the decision envelope, tool name, exact argument keys, value types and bounds. Execution uses tenant-scoped models and the existing appointment availability service. Availability checks account for business/staff schedules, booking policy, active local holds and connected Google FreeBusy; calendar verification failures fail closed to staff handoff. The tool returns at most six local times and does not create a hold, appointment or Google event.

There are deliberately no model-invoked write tools. Booking creation, confirmation, cancellation and rescheduling remain in the deterministic booking workflow and its state machine. Never add a write tool without explicit authorization checks, idempotency, transactional state transitions, provider-outcome recovery and dedicated regression coverage.

## 6. Failure and fallback

The Groq adapter classifies missing configuration, authentication/provider errors, rate limits, timeouts and malformed responses without exposing raw provider errors. The FAQ assistant falls back to a high-confidence exact/keyword FAQ answer or handoff. No automatic retry loop is used for every customer message.

No secondary paid provider is enabled by default. A provider HTTP 429 activates a configurable, shared cooldown (default 60 seconds) so every tenant using the same Groq credential avoids repeatedly hitting the rate-limited endpoint. Deterministic answers remain available during cooldown; requests needing model selection fall back to staff handover. The cooldown is bounded to one hour and can be disabled with a zero-second setting.

## 7. Prompt-injection controls

- Treat customer content, uploaded knowledge, and retrieved text as untrusted data.
- System rules and application authorization remain authoritative.
- Retrieved content cannot grant tools, reveal secrets, or override tenant boundaries.
- Never include another tenant's data in context.
- Do not reveal system prompts, credentials, internal notes, or private customer records.
- Validate every selected FAQ ID against the current tenant's retrieved candidate set.
- The final answer is copied from approved FAQ records, not generated by the model.

## 8. Human-readable responses

Use concise, business-appropriate language and the tenant's configured locale. Clearly distinguish suggestions from confirmed actions. If an operation is pending or failed, say so accurately and offer a staff handover.


## 9. Reply race and human takeover safety

Automated replies are generated outside the conversation lock so slow model calls do not hold database locks. Before an automated outbound message is persisted, the message service re-locks the conversation and revalidates that automation is still active and that the referenced inbound message is still the latest customer message. A staff takeover or newer customer message during model generation therefore blocks the stale AI reply. Regression tests simulate both race windows; the queue may retry the rejected job, but the next attempt exits because the conversation state or inbound-message ordering has changed.
