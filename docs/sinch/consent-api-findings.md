# Sinch Consent Management API: Empirical Findings

Findings from live API testing against the Sinch Conversation API (2026-03-28 to 2026-04-01). These supplement the [official documentation](https://developers.sinch.com/docs/conversation/consent-management) which lacks endpoint-level detail.

## Prerequisites

Consent Management must be enabled in the Sinch dashboard (Conversation API > Apps > [app] > Consent management > Edit). This requires accepting [terms and conditions](https://sinch.com/legal/conversation-api-old/consent-management-feature/). Without this step, all consent endpoints return 404.

## Confirmed Endpoint Structure

```
GET /v1/projects/{project_id}/apps/{app_id}/consents/{list_type}
```

### Valid list types

- `OPT_OUT_ALL` — standard opt-out, blocks all message types
- `OPT_OUT_MARKETING` — blocks marketing messages only
- `OPT_OUT_NOTIFICATION` — blocks notification messages only

### Invalid list types (return 400)

- `OPT_IN` — not a valid list type
- `identities`, `audit` — not valid list type values (these appear in the docs as sub-resources but are actually query parameters or separate endpoints)

## Consent Lists Are Lazily Created

Querying a list type that has no entries returns 404 with:

```json
{
  "error": {
    "code": 404,
    "message": "ListType for projectId '{pid}', appId '{aid}' and type 'OPT_OUT_ALL' does not exist",
    "status": "NOT_FOUND"
  }
}
```

This means the list only exists after at least one person has opted out via that list type. A 404 on a list type does NOT mean the API is unavailable — it means nobody has opted out yet.

## The Consent API Is Read-Only

**You cannot programmatically add or remove numbers from consent lists.** All write attempts return 501:

```json
{
  "error": {
    "code": 501,
    "message": "Method Not Allowed",
    "status": "UNIMPLEMENTED"
  }
}
```

Tested and confirmed unimplemented:
- `POST /consents/OPT_OUT_ALL` (501)
- `PUT /consents/OPT_OUT_ALL` (501)
- `POST /consents/OPT_OUT_ALL:add` (501)
- `POST /consents/OPT_OUT_ALL:register` (501)
- `POST /consents:register` (404)
- `POST /consents:opt-out` (404)

Numbers are added to consent lists **only** when a real person texts a STOP keyword to the number. There is no API to simulate or inject opt-outs.

## Implications for Our Architecture

### Admin opt-out cannot sync to Sinch

If an admin manually marks a patient as opted out in OpenEMR, we cannot propagate that to Sinch's consent list. Sinch will still attempt to deliver messages to that number. The admin opt-out is local-only.

This means:
- **Sinch → OpenEMR sync works** (query the consent API to learn about STOP events)
- **OpenEMR → Sinch sync does NOT work** (no write API)
- Admin opt-out must be enforced at the application level (skip the send before calling Sinch)

### Testing requires real phones

There is no way to create test opt-out data programmatically. To test the consent query flow end-to-end, someone must text STOP from a real phone number to the Sinch-linked number. The opt-out list will then be queryable.

### A 404 on the consent list is not an error

Application code must treat a 404 on `GET /consents/OPT_OUT_ALL` as "no one has opted out" (equivalent to an empty list), not as an API failure.

## Processing Mode

The app's processing mode can be switched via API:

```
PATCH /v1/projects/{project_id}/apps/{app_id}
{"processing_mode": "DISPATCH"}
```

Confirmed: switching between CONVERSATION and DISPATCH works and takes effect immediately.

## Default Originator Required for Message Delivery

Messages sent via the Conversation API were silently dropped (accepted with 200 but never delivered) until a **Default Originator** was configured on the SMS service plan.

**Root cause:** The Conversation API sends via the SMS service plan (OpenCoreEMR_RA), which has two numbers assigned (+1XXXXXXXXXX and +1YYYYYYYYYY). Both are registered to 10DLC campaign CO07D9V. However, without a Default Originator set, Sinch may attempt to send from a number not linked to the campaign, causing carrier-level rejection.

**Fix:** SMS > Service APIs > OpenCoreEMR_RA > Set Default Originators > United States > select +1XXXXXXXXXX > Configure.

**Key lesson:** The Sinch API returns 200 (accepted) even when the message will never be delivered. There is no synchronous delivery error — you must check delivery reports or the SMS analytics dashboard to diagnose failures. This makes debugging silent: the send appears to succeed.

**Documentation note:** The provisioning guide must include setting a Default Originator as a required step, not optional.

## Credential Configuration

The API credentials must belong to the same Sinch project as the Conversations app. Credentials from a different project can authenticate but silently fail to access the target project's apps.

Note: Sinch "parent" and "sub" projects are effectively independent projects — there is no real hierarchy. A subproject's credentials grant no access to a parent project's resources, and vice versa. The naming is misleading.

In practice, this means:
- Verify which Sinch project owns the Conversations app you are targeting.
- Ensure the API key/secret you configure are issued for that same project.
- Do not assume credentials from one project (parent or sub) work for another.

Details about where these credentials are stored (e.g., vault names, item labels, CLI commands) should be documented in an internal runbook or secret-management guide, not in this repository.

## Refutation Condition 5: REFUTED — Primary Mode Does Not Block Sends

**Tested 2026-04-01.** Consent Management in Primary mode does NOT block outbound messages sent via the Conversation API to opted-out numbers.

Test sequence:
1. Sent message to +1XXXXXXXXXX — delivered ✓
2. +1XXXXXXXXXX replied STOP — auto-reply received: "You have opted out of all communications."
3. Verified `GET /consents/OPT_OUT_ALL` shows `1XXXXXXXXXX` in the list ✓
4. Sent "This message should be BLOCKED by Consent Management" — **delivered** ✗
5. Waited, re-verified opt-out still in list, sent again — **delivered** ✗

**Conclusion:** The consent list is correctly populated by STOP keywords, and the auto-reply works, but **Primary mode does not enforce blocking on sends via the Conversation API**. The block may only apply to sends via the SMS REST API (not routed through the Conversation API), or "Primary" may only mean Sinch sends the auto-reply and records the opt-out — not that it blocks subsequent sends.

**Impact on ADR-0001:** The fail-open send design ("use cached value on API failure because Sinch blocks anyway") is **not safe**. The application must enforce opt-out checks before every send. The consent API remains valuable for querying opt-out status, but enforcement is our responsibility.

## Refutation Condition 1: PASSED — Consent API Queries Work

**Tested 2026-04-01.** After a real STOP message, the consent list is queryable:

```
GET /v1/projects/{pid}/apps/{aid}/consents/OPT_OUT_ALL
{
  "identities": [{"identity": "1XXXXXXXXXX"}],
  "next_page_token": ""
}
```

The endpoint returns identities without the `+` prefix (e.g., `1XXXXXXXXXX` not `+1XXXXXXXXXX`).

## Missing CTIA Keywords

The default English keyword configuration only has "stop" for opt-out and "start" for opt-in. CTIA Messaging Principles require honoring additional keywords:

**Required opt-out keywords:** STOP, END, CANCEL, QUIT, UNSUBSCRIBE
**Required opt-in keywords:** START, UNSTOP, SUBSCRIBE

The FCC's April 2025 order further broadened this to "any reasonable means" of revocation. At minimum, add the CTIA-required keywords in the Sinch dashboard under Consent management > Keywords > English.

Note: Sinch keyword matching is case-insensitive and exact-match only (the keyword must be the entire message, not a substring).

## Open Questions

- **Why doesn't Primary mode block sends?** The dashboard and docs both say "any subsequent mobile terminated messages will be blocked by Sinch" in Primary mode, but our testing shows messages are delivered to opted-out numbers. Possible explanations: (a) blocking only applies via the SMS REST API, not the Conversation API `messages:send` endpoint; (b) there's propagation delay longer than our test window; (c) there's an additional configuration step not documented. **This needs to be raised with Sinch support.**
- Does the consent list store channel identity with or without the `+` prefix? (Our test showed `1XXXXXXXXXX` without `+` — need to confirm this is consistent.)
- How does pagination work for large consent lists? (`next_page_token` was empty in our test.)
- Does `GET /consents/OPT_OUT_ALL` support pagination? Query parameters for filtering by channel or identity?
- Are there separate "get identities" and "get audit records" sub-endpoints, as the docs suggest? What are the exact paths?
- Does the consent list update when someone texts START? Does the entry get removed, or is a separate OPT_IN event recorded?

## Dashboard Note

The Consent Management settings page shows an "Additional fees/message" badge. The [T&C](https://sinch.com/legal/conversation-api-old/consent-management-feature/) says "the Service is free of charge" and the [developer docs](https://developers.sinch.com/docs/conversation/consent-management) say "no extra charges for MO messages processed." The auto-reply messages (STOP/START confirmations) are charged as normal MT messages. Clarify the badge meaning with Sinch.

## Validation Progress — Condition 2: PASSED

**Tested 2026-04-01.** After texting START:
- Auto-reply received: "You have opted in to all communications. To stop, send STOP to this number."
- `GET /consents/OPT_OUT_ALL` returns `{"identities": [], "next_page_token": ""}` — number removed from list

START keywords ARE processed in DISPATCH mode. The consent list correctly updates in both directions.

## CTIA Keywords Updated

Added missing CTIA-required keywords to the Sinch dashboard (2026-04-01):

**Opt-out (was: Stop only):** Stop, End, Cancel, Quit, Unsubscribe
**Opt-in (was: Start only):** Start, Unstop, Subscribe

Dashboard path: Conversation API > Apps > Sinch Test > Consent management > Keywords > English > Edit (⋮ menu)
