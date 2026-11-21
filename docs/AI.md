# AI and FAQ Architecture

## 1. Provider strategy

Groq is the configured initial provider. Application services depend on AiProviderInterface, not on Groq-specific SDK classes. GroqProvider uses Groq's OpenAI-compatible chat-completions endpoint with a configured model, base URL, timeout, zero temperature and bounded output tokens.

Configuration is server-side through GROQ_API_KEY, GROQ_MODEL, GROQ_BASE_URL and GROQ_TIMEOUT. Do not store provider credentials in tenant records, return them to the browser, or log them. No secondary paid provider is enabled.

The current interface exposes bounded generate(messages, options). Health checks, usage metering, provider selection and a secondary provider remain future work.

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

## 3. Remaining processing pipeline

The FAQ service is now connected to a queued inbound-text workflow. Newly persisted inbound text messages enqueue one tenant-scoped reply job after the webhook transaction commits. The job checks that the conversation is still open, `AUTO_ACTIVE`, and that the triggering message is still the latest inbound message before invoking the assistant. If no approved FAQ evidence is available, it moves the conversation to `WAITING_FOR_STAFF` without inventing a response. If a grounded answer is available, it sends the exact approved answer through the official WhatsApp adapter.

Automated outbound messages store a same-tenant `reply_to_message_id` with a unique constraint. Reprocessing the same job returns the existing outbound attempt instead of sending another message. Human takeover and a newer inbound message suppress stale queued replies.

Remaining steps:
1. detect intent (FAQ, booking, handover, greeting, unsupported);
2. combine published FAQs with structured business facts such as hours, services and prices;
3. expand staff handover workflow and notifications; the initial `AUTO_ACTIVE` gate and `WAITING_FOR_STAFF` fallback are implemented;
4. execute only allowlisted application tools with schema-validated arguments and tenant authorization;
5. add bounded usage metrics, provider latency and safe operational telemetry.

## 4. Grounding and refusal behavior

The assistant may answer only from approved tenant knowledge and live system results. It must not infer or invent:
- prices, discounts, taxes, or payment policies;
- opening hours, holidays, or location details;
- staff identity or availability;
- appointment availability or booking success;
- cancellation/refund rules;
- stock or service availability.

When required information is missing or conflicting, return handoff. A model confidence score alone is not proof that a fact is correct.

## 5. Tool execution

The model does not receive unrestricted SQL, filesystem, network, or administrative access. Future tools are explicit application actions, such as:
- get_business_hours
- list_services
- get_service_price
- find_available_slots
- create_booking
- request_human_handover

Each tool must validate arguments, check tenant ownership, apply rate/abuse limits, and return a typed result. Booking tools must follow the booking state machine and cannot report success prematurely.

## 6. Failure and fallback

The Groq adapter classifies missing configuration, authentication/provider errors, rate limits, timeouts and malformed responses without exposing raw provider errors. The FAQ assistant falls back to a high-confidence exact/keyword FAQ answer or handoff. No automatic retry loop is used for every customer message.

No secondary paid provider is enabled by default. Do not repeatedly retry a rate-limited request on every incoming message.

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
