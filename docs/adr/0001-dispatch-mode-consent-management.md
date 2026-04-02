# ADR-0001: DISPATCH mode with Sinch Consent Management handles opt-out without webhooks or inbound message processing

- **Status:** Proposed
- **Date:** 2026-03-28
- **Decision-Makers:** Michael A. Smith

## 1. Context (The Problem)

The Sinch Conversations module is a **message dispatch agent** — it sends outbound SMS (appointment reminders, notifications) on behalf of clinics. It does not need to receive or process arbitrary inbound messages. However, US carrier regulations and the TCPA require that patients can opt out of receiving messages by texting STOP, and that the opt-out is honored immediately.

This creates disproportionate architectural complexity for a send-only tool:

**Three independent opt-out authorities.** When a patient texts STOP, the opt-out is enforced at three layers — the carrier (network-level block), Sinch (platform-level block), and OpenEMR (application-level consent records). Each layer has different update mechanisms, different authorities, and can desynchronize.

**Inbound message delivery.** Sinch delivers inbound events (including STOP keywords) via webhooks — push notifications to a registered URL. In DISPATCH mode, there is no message storage and no polling API for messages. The only way to learn about inbound events is via webhooks.

**Multi-tenant webhook routing.** In our Kubernetes deployment, multiple clinic tenants share a single Sinch app and phone number. Sinch sends webhooks to one URL per app. There is no built-in mechanism to route an inbound STOP notification to the correct tenant's OpenEMR instance.

**UI consistency.** Clinic staff need to see accurate opt-out status in the patient chart. Stale consent records cause either unnecessary send failures (patient opted back in but OpenEMR still shows opted-out) or compliance-violating send attempts (patient opted out but OpenEMR doesn't know yet).

## 2. Hypothesis

*Enabling Sinch Consent Management in Primary mode and polling the Sinch Consent API to reconcile OpenEMR's records will correctly handle opt-out and re-subscribe flows in DISPATCH mode, without requiring webhooks or inbound message processing, for both single-tenant and multi-tenant deployments sharing a Sinch app.*

### 2.1. Refutation Conditions

- **Condition 1: Sinch Consent API does not expose per-number opt-out status.**
  - **Validation Metric:** If the Sinch Consent Management API does not provide an endpoint to query whether a specific phone number is opted out (or to list all opted-out numbers for an app), the polling-based reconciliation strategy cannot work. Verify by calling the API with a test number that has opted out.

- **Condition 2: Consent Management does not process START keywords in DISPATCH mode.**
  - **Validation Metric:** If a patient texts START to the 10DLC number and Sinch does not remove them from the opt-out list (verifiable via the Consent API), then re-subscribe cannot be detected without webhooks. Test by texting START from a previously opted-out number and querying the API.

- **Condition 3: Carrier-level block prevents Sinch from seeing START messages.**
  - **Validation Metric:** If the carrier blocks START messages from reaching Sinch (because the number is on the carrier's block list), then Sinch never processes the opt-in and the Consent API never updates. Test by texting START from a carrier-blocked number and checking whether Sinch's consent status changes.

- **Condition 4: Per-send consent API calls create unacceptable API volume or latency.** *(Withdrawn — unfalsifiable)*
  - **Validation Metric:** If a batch reminder job for N patients requires N sequential Sinch API calls and the total time exceeds the batch window, or the call volume exceeds Sinch's rate limits, the query-on-demand approach needs a caching layer.
  - **Withdrawn:** The consent API query volume is bounded by the SMS TPS rate (10 messages/second on this service plan), which is itself bounded by carrier throughput. A consent query cannot create volume that exceeds the send volume — the bottleneck is always the carrier, not the API. This condition cannot be meaningfully tested in isolation.

- **Condition 5: Sinch Consent Management (Primary mode) fails to block a send to an opted-out number.**
  - **Validation Metric:** The entire "use cached value on API failure" design depends on the invariant that Sinch blocks opted-out sends at the platform level regardless of our application state. If a message is delivered to a number that Sinch's own consent list shows as opted out, this invariant is broken and the architecture requires a fail-closed consent check (abort send on API failure). Test by sending to a known opted-out number and verifying Sinch rejects it.

- **Condition 6: Consent API latency degrades the admin UI experience.**
  - **Validation Metric:** If the live consent query adds more than 2 seconds of latency to the patient chart view (including timeout), the UX is unacceptable. The fallback (show cached value immediately, update asynchronously) mitigates this, but if the API is consistently slow, the cached-with-timestamp experience becomes the norm rather than the exception.

## 3. Considered Options & Rationale for Refutation

- **Webhooks (current implementation):**
  - **Refutation:** Sinch's webhook URL parser rejects HTTP Basic Auth credentials in URLs. The alternative auth methods (HMAC secret token, OAuth2) work, but webhooks create a multi-tenant routing problem — all tenants share one Sinch app, so all webhooks go to one URL. Routing requires either a shared webhook receiver service (additional infrastructure) or one Sinch app per tenant (additional cost/complexity). For a send-only tool that needs to handle one inbound case (STOP), this is disproportionate.

- **CONVERSATION mode with tenant-side polling:**
  - **Refutation:** CONVERSATION mode stores messages server-side and supports polling via the Messages API. Each tenant could poll for inbound STOP messages. However, CONVERSATION mode is designed for bidirectional messaging with persistent conversation state — unnecessary overhead for a dispatch-only tool. It also changes the app's processing semantics (conversations are created, contacts are managed server-side) which adds complexity without benefit. Finally, polling for messages still requires filtering by phone number per-tenant, with the same eventual-consistency trade-offs as consent API polling.

- **One Sinch app per tenant:**
  - **Refutation:** Clean isolation — each tenant has their own app, phone number, and webhook URL. But each app requires its own 10DLC campaign registration ($15+/month per number, days for approval), its own provisioning flow, and its own webhook management. For tenants that send a few dozen reminders per month, this cost and complexity is not justified. This option remains viable as a fallback if the shared-app approach is refuted.

- **Reactive sync (detect opt-out from delivery failures only):**
  - **Refutation:** When Sinch blocks a send (Primary mode), the API returns a delivery failure. We could detect this and update records retroactively. However, this only works for STOP (opt-out) — there is no equivalent signal for START (opt-in). A patient who re-subscribes would remain marked as opted-out in OpenEMR indefinitely, since we'd never attempt to send to them again. The consent API polling approach handles both directions.

## 4. Decision & Rationale for Corroboration

**DISPATCH mode + Sinch Consent Management (Primary) + Consent API polling.**

The architecture uses **query-on-demand** — consent status is checked at the two moments it matters, not on a schedule:

1. **Send path (asynchronous).** The appointment reminder job queries the Sinch Consent API for each patient's phone number before sending. If opted out, update OpenEMR's local record and skip the send. If the consent API call fails (timeout, network error, Sinch outage), **use the cached local value and proceed** — but this is a degraded mode, not a safe default. Testing revealed that Sinch Consent Management (Primary mode) does NOT block sends to opted-out numbers via the Conversation API (see RC-5 refutation). The application is the sole enforcer of opt-out. A stale cache that says "opted in" when the patient actually opted out will result in a delivered message. This risk is bounded by the query-on-demand model: the cache is refreshed on every send attempt, so staleness only occurs if the consent API is unreachable.

2. **Admin UI path (synchronous).** When clinic staff view a patient's consent status in the chart, query the Sinch Consent API live for that phone number. Show the cached local value immediately as a fallback, then update the display if the live query returns a different status. If the API is unavailable or times out, show the cached value with an "as of [timestamp]" indicator. No blocking spinner, no broken page if Sinch is down.

3. **Admin opt-out (manual).** Admin marks a patient as opted out in OpenEMR → update local record only. The Sinch Consent API is read-only (see [consent API findings](../sinch/consent-api-findings.md)) — there is no write endpoint to propagate the opt-out to Sinch. The application must enforce the admin opt-out by skipping the send before calling the Sinch API. If a message is sent despite the local opt-out (bug), Sinch will still deliver it — the platform-level block only applies to STOP-keyword opt-outs, not application-level ones.

4. **Admin opt-in attempt.** If the patient previously texted STOP, the carrier block is immutable from our side. Even if we remove the Sinch-level block via API, the carrier still blocks delivery. The UI must communicate this clearly: "Patient opted out via SMS. Patient must text START to [number] to re-subscribe." Do not present an opt-in toggle that silently fails on the next send.

5. **Opt-out handling (STOP).** Patient texts STOP → carrier blocks at network level. Sinch Consent Management (Primary mode) records the opt-out and exposes it via the Consent API but does not prevent sends made via the Conversations `messages:send` endpoint. OpenEMR must enforce the opt-out by consulting the Consent API and skipping the send before calling Sinch. OpenEMR learns about the opt-out on the next send attempt or the next time an admin views the patient's chart — whichever comes first.

6. **Re-subscribe handling (START).** Patient texts START → carrier unblocks and Sinch Consent Management updates its consent list to reflect the new opt-in. OpenEMR detects the change on the next query-on-demand (send attempt or admin chart view) and may resume sending via `messages:send`, subject to its own consent rules.

7. **Multi-tenant.** All tenants share one Sinch app and phone number. Each tenant independently queries the Consent API for their own patients' phone numbers. No webhook routing, no shared infrastructure, no scheduled reconciliation jobs.

No periodic polling. No cron jobs. No webhooks. Consent is queried at the moment of action and cached locally for display purposes.

This approach survives the considered refutations because:

- It requires zero webhook infrastructure and zero background jobs.
- It works in DISPATCH mode without conversation storage.
- It handles both STOP and START through the same query-on-demand mechanism.
- Compliance is enforced at the application level: OpenEMR MUST query Sinch Consent Management before every send and block messages to opted-out numbers, because Primary mode does not block Conversation API sends.
- The admin UI degrades gracefully when the API is unavailable (cached value + timestamp).
- Multi-tenant works without routing — each tenant queries independently.

**Pending validation:** Refutation condition RC-3 remains to be tested against the live Sinch API before this ADR is accepted. RC-1 and RC-2 were validated on 2026-04-01 (see table below). Specifically for RC-3, we need to confirm the Consent API exposes queryable per-number opt-out status and that START keywords are processed in DISPATCH mode.

### 4.2. Validation Progress

| Date | Action | Result |
|------|--------|--------|
| 2026-03-28 | Switched app to DISPATCH mode via API | Confirmed: `PATCH /apps/{id}` with `{"processing_mode": "DISPATCH"}` works |
| 2026-03-28 | Probed consent API endpoints (pre-enablement) | All returned 404 — Consent Management not yet enabled on the app |
| 2026-03-28 | Enabled Consent Management in Sinch dashboard | Primary mode, accepted T&C |
| 2026-03-28 | Discovered correct endpoint: `GET /consents/{list_type}` | Endpoint exists. Returns 404 when list is empty (lazily created). Valid types: OPT_OUT_ALL, OPT_OUT_MARKETING, OPT_OUT_NOTIFICATION |
| 2026-03-28 | Attempted programmatic opt-out registration | **All write endpoints return 501 UNIMPLEMENTED.** Consent API is read-only. Numbers are added only when a real person texts STOP. |
| 2026-03-28 | Tested admin opt-out propagation | **Cannot sync OpenEMR → Sinch.** Admin opt-out is local-only; application must enforce it before calling Sinch send API. |
| 2026-04-01 | Fixed credentials: parent project keys required for Sinch Test app | Subproject credentials authenticate but cannot access parent project apps. |
| 2026-04-01 | Set Default Originator on SMS service plan | Messages were silently dropped without it. Set a campaign-registered number as default originator for US. |
| 2026-04-01 | Sent test message | **Delivered.** First successful end-to-end send via Conversation API. |
| 2026-04-01 | Texted STOP, queried consent API | **RC-1 PASSED.** STOP processed, auto-reply sent, `GET /consents/OPT_OUT_ALL` returns the number. |
| 2026-04-01 | Sent to opted-out number (twice) | **RC-5 REFUTED.** Both messages delivered despite number being in OPT_OUT_ALL list. Primary mode does NOT block sends via the Conversation API. |
| 2026-04-01 | Texted START, queried consent API | **RC-2 PASSED.** START processed in DISPATCH mode, number removed from OPT_OUT_ALL list. |
| 2026-04-01 | Added CTIA keywords | Updated English keywords: opt-out (Stop, End, Cancel, Quit, Unsubscribe), opt-in (Start, Unstop, Subscribe). |
| 2026-04-01 | Evaluated RC-4 | **RC-4 WITHDRAWN.** Consent query volume bounded by SMS TPS rate (10/s). Cannot exceed send volume. Unfalsifiable. |

**RC-5 refutation impact:** The fail-open send design is not safe. Sinch Consent Management (Primary mode) records opt-outs and sends auto-replies, but does not enforce blocking on outbound sends via the Conversation API. The application must check consent status before every send and enforce the block itself. See [consent API findings](../sinch/consent-api-findings.md) for full details.

## 5. Consequences (Positive and Negative Predictions)

- **Positive:** No webhook infrastructure, no background jobs, no cron. The module is a pure API client — it sends messages and queries consent status on demand. Multi-tenant deployment requires no additional services. Consent Management in Primary mode records opt-outs and provides a queryable API, but the application must enforce blocking (Primary mode does not block Conversation API sends — see RC-5 refutation).

- **Positive:** Admin UI shows live consent status when the API is reachable, with graceful fallback to cached status plus timestamp when it isn't. No ambiguity about whether the displayed status is current.

- **Positive:** The admin cannot accidentally re-enable SMS for a carrier-blocked patient. The UI explains the situation and directs the patient to text START — preventing a class of compliance errors where staff believe they've re-enabled consent but sends silently fail.

- **Negative:** The local consent record is a cache, not a source of truth. Between a patient texting STOP and the next query-on-demand, OpenEMR's record is stale. For appointment reminders (sent hours/days in advance), staleness is bounded by the send schedule. For the admin UI, staleness is bounded by the next chart view. In both cases, the application must enforce opt-out by checking Sinch consent state before sending via the Conversation API, while carrier-level blocks may still apply independently.

- **Negative:** If refutation condition 3 holds (carriers block START from reaching Sinch), patients who opt out via carrier STOP may be unable to re-subscribe through the same channel. This would require an alternative opt-in mechanism (web form, in-person consent). This is a carrier/regulatory constraint, not an architectural one.

- **Negative:** Each send and each admin chart view makes a Sinch API call. For batch sends, this is N API calls per batch (one per patient). For the admin UI, this is one call per chart view. Monitor API usage against Sinch rate limits. If rate limits become a concern, introduce a short TTL cache (e.g., 5 minutes) on consent status per number.

## 6. Data Localization and Compliance

The [Sinch Conversation API terms](https://sinch.com/legal/conversation-api/) include a data localization clause:

> **Data Localisation.** Services provisioned through Conversation API may be hosted in various locations. During implementation, Customer will configure its hosting location(s) through either the Sinch Dashboard or its Sinch account manager at service setup. If Customer wishes to change the hosting location after setup, Customer must contact Sinch. Changes to the hosting location after setup may incur additional fees.

Our app is configured with `region=us`, which routes API traffic through `us.conversation.api.sinch.com`. The terms state that the hosting location is chosen at setup and persists unless explicitly changed.

The [Consent Management terms](https://sinch.com/legal/conversation-api-old/consent-management-feature/) do not add any data localization constraints beyond the general Conversation API terms. The consent service is free of charge and governed by the same Terms of Service.

**PHI exposure is minimal by design.** Outbound messages use templates with no patient names or clinical details — only clinic name, appointment time, and opt-out instructions. Inbound messages are expected to consist only of STOP/START keywords; we do not solicit or encourage freeform messaging. The only data Sinch stores in the consent list is "this phone number opted out of messages from this app" — no clinical content.

Phone numbers linked to a healthcare provider could technically constitute PHI under HIPAA, but the risk profile is low: the consent list reveals only that a phone number interacted with a healthcare-associated messaging app, not any clinical information about the person.

**Open questions for product/legal review:**
- Does "US region" guarantee all data (including consent lists) stays in US data centers?
- Is a BAA (Business Associate Agreement) required or available from Sinch? (Given minimal PHI exposure, this may not be necessary — but confirm with counsel.)

## References

- [Sinch Consent Management](https://developers.sinch.com/docs/conversation/consent-management) — Platform-level opt-out handling for the Conversation API
- [CTIA Messaging Principles and Best Practices (May 2023)](https://api.ctia.org/wp-content/uploads/2023/05/230523-CTIA-Messaging-Principles-and-Best-Practices-FINAL.pdf) — Industry standard for A2P messaging compliance
- [FCC TCPA Consent Revocation Order (2024)](https://talk-q.com/sms-messaging-regulation-in-the-us) — Updated rules on consent revocation, effective April 2025
- [Sinch US SMS Compliance Guide](https://sinch.com/wp-content/uploads/pdf/Sinch-SMS-US%20Compliance%20Guide-240213.pdf) — Sinch-specific compliance guidance
- [10DLC Opt-Ins and Opt-Outs](https://www.10dlc.org/en/home/Opt-Ins) — Campaign Registry requirements
- [Sinch Conversation API Terms](https://sinch.com/legal/conversation-api/) — Data localization, retention, and service terms
- [Sinch Consent Management Terms](https://sinch.com/legal/conversation-api-old/consent-management-feature/) — Consent service terms (free, governed by general ToS)
- [RFC #83](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/83) — Original discussion of the opt-out architecture options
