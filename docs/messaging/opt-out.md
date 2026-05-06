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
- **STOPALL**
- **QUIT**
- **END**
- **CANCEL**
- **UNSUBSCRIBE**

These are a subset of the "reasonable means per se" keywords defined by
the FCC's consent revocation rule (47 CFR 64.1200(a)(10)), effective
April 11, 2025.

> **Not yet implemented:** The FCC rule also lists **REVOKE** and
> **OPT OUT** (two words) as per-se revocation keywords. The module does
> not handle these yet. See [Regulatory Considerations](../regulatory.md)
> for the full list.

## Opt-Out Flows

### Via Text Message

1. Patient replies with an opt-out keyword (e.g., STOP)
2. The carrier may block future messages at the network level
3. Sinch delivers the STOP as a `MESSAGE_INBOUND` webhook event
4. The module's `KeywordHandlerService` detects the keyword
5. `ConsentService::optOut()` records the opt-out in
   `oce_sinch_patient_consent` (the module's exception store) and sets the
   chart's **Allow SMS** field to No
6. The patient no longer receives automated messages

### Via Patient Chart

1. A staff member navigates to the patient's chart
2. Set **Allow SMS** (`hipaa_allowsms`) to No
3. The chart `hipaa_allowsms='NO'` immediately gates all future sends —
   no module-side mirroring is required

## Opt-In via Patient Chart

Staff capture verbal or written consent during registration or any
subsequent visit. The chart `hipaa_allowsms` field is the source of
truth for SMS opt-in: setting it to YES is the consent record.

1. The patient provides consent (verbally or in writing) and a mobile number
2. A staff member registers the patient (or edits the chart) with
   **Allow SMS** (`hipaa_allowsms`) set to Yes and `phone_cell` populated
3. `MessageService` will now treat the patient as eligible for SMS based
   on the chart alone — no separate module opt-in row is required
4. `PatientConsentListener` observes the `patient.created` /
   `patient.updated` event and, on a NO → YES transition, sends the
   opt-in confirmation SMS so the patient knows they will start receiving
   messages from the clinic
5. If a stale module-side opt-out exists (e.g., the patient previously
   texted STOP), the listener skips the welcome SMS and logs the skip —
   staff must clear the explicit opt-out deliberately before automated
   messages resume

The listener is a no-op for YES → NO transitions: chart NO already gates
sends, so no module-side action is required. It is also a no-op when the
chart save does not change `hipaa_allowsms`, when the new value matches
the current value, or when `phone_cell` is blank on both the old and new
patient record.

> **Welcome-SMS coverage caveat:** the listener subscribes to OpenEMR's
> `PatientUpdatedEvent` and `PatientCreatedEvent`. The legacy
> `demographics_save.php` and `new_patient_save.php` chart save paths
> dispatch `PatientUpdatedEventAux` (or no event at all), so a NO→YES
> toggle through those paths will not trigger the welcome SMS. **Patient
> eligibility is unaffected** — `MessageService::assertPatientEligible()`
> reads `hipaa_allowsms` live, so the patient is still reachable by
> appointment reminders the moment the chart shows YES. Reliable welcome
> coverage across all save paths is tracked separately.

> **Note:** If the carrier blocked messages at the network level after
> a STOP, the patient may also need to text START to the same number
> to unblock carrier-level filtering.

## Consent Check

To send a message the module requires:

| Check | Source | What it represents |
|-------|--------|-------------------|
| `hipaa_allowsms = 'YES'` | `patient_data` (chart) | Patient-level opt-in (TCPA prior express consent) — the source of truth |
| No `opted_out=TRUE` row in `oce_sinch_patient_consent` for `(patient_id, phone_number)` | Module exception store | Patient-side opt-out the chart cannot represent (STOP keyword, channel-native opt-out) |
| No `carrier_blocked=TRUE` row in `oce_sinch_patient_consent` for `(patient_id, phone_number)` | Module exception store | Carrier- or Sinch-side block discovered via SMPP delivery error or consent-API reconciliation — closes the partial-write window between `setCarrierBlock()` and the paired `optOut()` |

The module table is consulted **only for exceptions** that override the
chart's YES. The absence of any module row means we have no exception on
file — the chart YES alone is sufficient.

The `carrier_blocked` check above closes the gate-level part of the
broader Sinch-side desync work in
[issue #42](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/42),
which still tracks the proactive consent-API reconciliation job, the
staff workflow for re-enabling a Sinch-blacklisted number, and the
chart-side indicator.

## Compliance Notes

- The module must honor opt-out requests within **10 business days**
  per FCC rules. In practice, the module processes them immediately
  upon receiving the webhook.
- Opt-out via SMS blocks automated texts and prerecorded calls from
  the sender. It does not block email, patient portal messages, live
  phone calls, or postal mail.
- See [Regulatory Considerations](../regulatory.md#fcc-consent-revocation-rule)
  for details on the pending "revoke all" provision.
