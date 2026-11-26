# Staging load and abuse verification

These k6 scripts are operator-run acceptance tools. They are deliberately excluded from ordinary CI because they must target an isolated staging deployment, never a production tenant or customer-facing endpoint.

## Preconditions

- Use a dedicated staging deployment and a dedicated load-generator IP.
- Confirm staging database backups/snapshot and that the test environment contains no real customer data.
- Complete the deployment preflight and confirm `/health/ready` returns HTTP 200.
- Confirm the staging queue worker and scheduler are running.
- Do not run these scripts against production.
- Install k6 separately; it is not a runtime or Composer/npm dependency of the application.

## Bounded platform smoke load

Run:

```sh
BASE_URL=https://staging.example.com k6 run tests/load/platform-smoke.js
```

The script ramps from zero to five virtual users, holds briefly, then ramps down. It checks `/up`, `/api/v1/health` and `/health/ready`. It sends no login, booking, webhook or WhatsApp mutation requests. Each virtual user waits 15 seconds between iterations, keeping readiness requests below the route's 30-per-minute shared-IP throttle during the five-user profile.

Default acceptance thresholds:

- HTTP request failure rate below 1%.
- p95 response time below 1,000 ms.
- At least 99% of endpoint checks pass.

These are initial staging guardrails, not a final capacity claim. Set workload and latency targets from expected tenant count, inbound message rate and hosting-plan limits before a release candidate is accepted.

## Readiness rate-limit abuse probe

Run only after confirming the staging endpoint is healthy and after allowing the dedicated runner IP's prior throttle window to expire:

```sh
BASE_URL=https://staging.example.com k6 run tests/load/readiness-rate-limit.js
```

The script sends 31 sequential GET requests from one virtual user to `/health/ready`. It expects requests 1–30 to return HTTP 200 and request 31 to return HTTP 429. This intentionally consumes the readiness endpoint's 30-per-minute allowance for that source IP; wait at least one minute before repeating it. It does not mutate business data.

## Evidence to retain

For each staging run, record the exact application commit, hosting plan/runtime, database branch/compute tier, test window, load-generator location, k6 version, virtual-user profile, p50/p95/p99 latency, error/check rates, queue age before/during/after, CPU/memory/database connection observations, and any 429/5xx responses. Keep secrets, session cookies, customer data and full request payloads out of the report.

A passing script is only a narrow health/latency and middleware-throttle check. It does not replace realistic inbound webhook throughput, concurrent booking, Meta/Google provider acceptance, queue-worker termination/recovery, database saturation, backup/restore or rollback drills. Those remain explicit staging and live-account release gates.
