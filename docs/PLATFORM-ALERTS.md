# Platform operational alert delivery

The platform operations dashboard evaluates five threshold alerts:

- AI provider success rate below its configured minimum (after the minimum sample size).
- Elevated seven-day average AI provider latency.
- Repeated Groq rate limits during the previous 24 hours.
- An eligible, unreserved queue job older than the configured backlog threshold.
- Repeated failed jobs during the previous 24 hours.

## Optional email delivery

Email delivery is disabled unless `PLATFORM_ALERT_EMAILS` contains one or more comma-separated recipient addresses. For example:

```dotenv
PLATFORM_ALERT_EMAILS=ops@example.com,oncall@example.com
PLATFORM_ALERT_EMAIL_COOLDOWN_MINUTES=30
```

The cooldown is clamped to 1–1,440 minutes. Each alert code is deduplicated independently for each recipient. The cooldown claim uses the configured Laravel cache store's atomic `add` operation so concurrent dashboard requests do not normally queue duplicate notifications. If queue dispatch fails, the claim is released so a later dashboard visit can retry.

Notifications are queued and contain only the alert title, severity, measured threshold detail and alert code. They do not include queue payloads, exception bodies, provider prompts/completions, credentials or customer data.

## Delivery trigger and operational requirements

An email is queued when an authorized platform operator opens the platform dashboard while an alert is active. This is event-triggered on dashboard access, not a continuously running pager: if no operator opens the dashboard, no new alert email is generated. The dashboard remains the source of truth.

Actual delivery requires valid Laravel mail configuration, a working queue connection and a running queue worker. The default development mailer is `log`; production SMTP must be configured and verified during the Hostinger staging rehearsal. Recipients should be controlled by the platform operator and not exposed in the browser.

No production SMTP configuration, recipient list, deployment or live email has been changed or tested by this repository change.
