# Opt-Out Handling

Patients can opt out of receiving SMS messages at any time. The module
supports opt-out via text message and via the patient chart. Both methods
must be honored per TCPA and FCC requirements.

For the full regulatory background, see
[Regulatory Considerations](../regulatory.md).

## Opt-Out Keywords

The following keywords, sent as a reply to any message from the module,
revoke the patient's SMS consent:

- **STOP**
- **QUIT**
- **END**
- **CANCEL**
- **UNSUBSCRIBE**

These are the "reasonable means per se" keywords defined by the FCC's
consent revocation rule (47 CFR 64.1200(a)(10)), effective April 11, 2025.

## Opt-Out Flows

### Via Text Message

1. Patient replies with an opt-out keyword (e.g., STOP)
2. The carrier may block future messages at the network level
3. Sinch delivers the STOP as a `MESSAGE_INBOUND` webhook event
4. The module's `KeywordHandlerService` detects the keyword
5. `ConsentService::optOut()` records the opt-out
6. The module sets the patient chart's **Allow SMS** field to No
7. The patient no longer receives automated messages

### Via Patient Chart

1. A staff member navigates to the patient's chart
2. Set **Allow SMS** (`hipaa_allowsms`) to No
3. The module checks this field before sending — messages are blocked
   immediately

## Opt-In (Re-subscribe)

A patient who previously opted out can opt back in:

1. The patient provides new consent (verbally or in writing)
2. A staff member sets **Allow SMS** to Yes in the patient chart
3. The `ConsentService` records a new opt-in
4. The patient resumes receiving messages

> **Note:** If the carrier blocked messages at the network level after
> a STOP, the patient may also need to text START to the same number
> to unblock carrier-level filtering.

## Dual Consent Check

The module requires **both** consent signals to be affirmative before
sending a message:

| Check | Source | What it represents |
|-------|--------|-------------------|
| `hipaa_allowsms` | `patient_data` table | Patient-level consent (TCPA prior express consent) |
| Consent record | `ConsentService` | Module-level opt-in/opt-out tracking |

If either is negative, the `MessageService` blocks the message.

## Compliance Notes

- The module must honor opt-out requests within **10 business days**
  per FCC rules. In practice, the module processes them immediately
  upon receiving the webhook.
- Opt-out via SMS blocks automated texts and prerecorded calls from
  the sender. It does not block email, patient portal messages, live
  phone calls, or postal mail.
- See [Regulatory Considerations](../regulatory.md#fcc-consent-revocation-rule)
  for details on the pending "revoke all" provision.
