# AI and FAQ Architecture

## 1. Provider strategy

Groq is the initial provider. Application code depends on an internal interface, not on Groq-specific SDK classes or response formats.

Conceptual contract:

```php
interface AIProviderInterface
{
    public function generate(AIRequest $request): AIResponse;
    public function healthCheck(): ProviderHealth;
}
```

Implement a Groq adapter and provider manager. Provider credentials are encrypted and resolved server-side. Model name, timeout, token budget, and enabled state are configuration, not hard-coded assumptions.

## 2. Processing pipeline

1. Normalize incoming customer message.
2. Load tenant, conversation, customer, and current automation state.
3. Stop if human handover is active.
4. Detect intent (FAQ, booking, handover, unsupported, greeting, other).
5. Retrieve only active knowledge belonging to the resolved tenant.
6. Prefer structured business facts and exact FAQ matches.
7. If generative synthesis is useful, send a bounded prompt with retrieved facts and explicit response constraints.
8. Validate the generated answer against retrieved facts and business rules.
9. Execute only allowlisted tools with schema-validated arguments and tenant authorization.
10. Recheck handover and conversation state before outbound delivery.
11. Record provider, model, retrieval references, latency, outcome, and usage without logging secrets.

## 3. Grounding and refusal behavior

The assistant may answer only from approved tenant knowledge and live system results. It must not infer or invent:
- prices, discounts, taxes, or payment policies;
- opening hours, holidays, or location details;
- staff identity or availability;
- appointment availability or booking success;
- cancellation/refund rules;
- stock or service availability.

When required information is missing or conflicting, ask a focused clarification or offer staff handover. A model confidence score alone is not proof that a fact is correct.

## 4. Retrieval order

1. Exact FAQ match.
2. Structured business profile, hours, services, prices, and policies.
3. Keyword/synonym search over published tenant knowledge.
4. Optional semantic retrieval only if an approved, affordable implementation is selected.
5. Escalate when evidence is insufficient.

Do not add a paid vector database or embedding service without approval. PostgreSQL full-text search and structured lookup are sufficient for the initial milestone.

## 5. Tool execution

The model does not receive unrestricted SQL, filesystem, network, or administrative access. Tools are explicit application actions, such as:
- `get_business_hours`
- `list_services`
- `get_service_price`
- `find_available_slots`
- `create_booking`
- `request_human_handover`

Each tool validates arguments, checks tenant ownership, applies rate/abuse limits, and returns a typed result. Booking tools must follow the booking state machine and cannot report success prematurely.

## 6. Failure and fallback

Classify provider errors:
- rate limited;
- authentication/configuration error;
- timeout/network;
- provider unavailable;
- invalid/malformed output;
- token/context limit.

Use bounded retries only for transient failures. If Groq is unavailable or rate-limited:
1. Use a configured secondary provider only if credentials and approval exist.
2. Otherwise use deterministic FAQ and guided workflow responses.
3. Escalate unsupported or consequential questions to staff.

No secondary paid provider is enabled by default. Do not repeatedly retry a rate-limited request on every incoming message.

## 7. Prompt-injection controls

- Treat customer content, uploaded knowledge, and retrieved text as untrusted data.
- System rules and application authorization remain authoritative.
- Retrieved content cannot grant tools, reveal secrets, or override tenant boundaries.
- Never include another tenant's data in context.
- Do not reveal system prompts, credentials, internal notes, or private customer records.
- Validate all model output before it is sent or used as tool input.

## 8. Human-readable responses

Use concise, business-appropriate language and the tenant's configured locale. Clearly distinguish suggestions from confirmed actions. If an operation is pending or failed, say so accurately and offer a staff handover.
