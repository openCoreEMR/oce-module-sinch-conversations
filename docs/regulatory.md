# Regulatory Considerations

This document covers the regulatory landscape for automated patient
messaging via SMS in a US healthcare context. It is intended for
AI-assisted humans making implementation decisions about this module.

This is not legal advice. Consult healthcare regulatory counsel before
making compliance decisions.

## Regulatory Framework at a Glance

| Regulation | Governs | Key concern for this module |
|------------|---------|----------------------------|
| [TCPA](#tcpa) | Automated calls and texts | Consent, opt-out handling, healthcare exemption conditions |
| [HIPAA](#hipaa) | Protected health information | PHI in message content, encryption, BAA with Sinch |
| [FCC Orders](#fcc-consent-revocation-rule) | TCPA implementation details | "Revoke all" scope, 10-business-day opt-out window |
| [CAN-SPAM](#can-spam) | Commercial email | Not applicable to SMS; noted here to clarify boundaries |
| [Carrier policies](#carrier-and-aggregator-requirements) | SMS delivery | STOP enforcement at the carrier level, independent of app logic |

## TCPA

The Telephone Consumer Protection Act (47 U.S.C. 227) restricts
automated calls and text messages. The implementing rules are at
[47 CFR 64.1200](https://www.ecfr.gov/current/title-47/chapter-I/subchapter-B/part-64/subpart-L/section-64.1200).

### Healthcare exemption

The FCC created a limited exemption in 2012 for healthcare messages
sent by or on behalf of a HIPAA covered entity or its business
associate. This module operates under this exemption when all
conditions are met.

**Qualifying message types** (47 CFR 64.1200(a)(2)):

- Appointment and exam confirmations and reminders
- Wellness checkup reminders
- Hospital pre-registration instructions
- Pre-operative instructions
- Lab results
- Post-discharge follow-up to prevent readmission

**Conditions** (all must be satisfied):

| Condition | Limit |
|-----------|-------|
| Recipient number | Must be the number provided by the patient |
| Frequency | Max 1 message/day, max 3 calls+texts/week per provider |
| Length | 160 characters or less for texts |
| Content | No marketing, solicitation, advertising, billing, or debt collection |
| HIPAA | Must comply with HIPAA Privacy Rule |
| Opt-out | Must offer an easy opt-out mechanism (reply STOP for texts) |
| Consent | Prior express consent (not written consent) for cell phones |

**What this means for the module:** Messages sent through this module
qualify for the exemption only if they stay within these bounds. The
module does not currently enforce the frequency or character limits;
these are the caller's responsibility. If a message contains
marketing or billing content, the full TCPA consent requirements
apply (prior express written consent).

**References:**
- [FCC-20-186 (2020 healthcare exemption order)](https://docs.fcc.gov/public/attachments/FCC-20-186A1.pdf)
- [Federal Register: Limits on Exempted Calls (2021)](https://www.federalregister.gov/documents/2021/02/25/2021-01190/limits-on-exempted-calls-under-the-telephone-consumer-protection-act-of-1991)
- [Bass Berry: TCPA Exemptions for Healthcare Companies](https://www.bassberry.com/news/tcpa-exemptions-for-healthcare-companies/)
- [Manatt: The TCPA and Healthcare](https://www.manatt.com/insights/newsletters/health-highlights/the-tcpa-and-healthcare-consent-exemptions-and-ri)

### Consent types

| Consent level | Required for | How obtained |
|---------------|-------------|--------------|
| Prior express consent | Healthcare texts to cell phones under the exemption | Patient provides their cell number to the provider |
| Prior express written consent | Non-exempt automated texts (marketing, billing) | Signed written agreement, including electronic |

The module's `ConsentService` tracks opt-in and opt-out at the
module level. The `hipaa_allowsms` field in OpenEMR's `patient_data`
table serves as the patient-level consent flag. Both must be
affirmative before sending (enforced by `MessageService`).

## FCC Consent Revocation Rule

In February 2024 the FCC adopted new rules on consent revocation
([FCC-24-16](https://www.fcc.gov/document/tcpa-rules-revoking-consent-unwanted-robocallsrobotexts),
codified at 47 CFR 64.1200(a)(10)).

### What took effect April 11, 2025

- Callers must honor opt-out requests within **10 business days**
- The following keywords are "reasonable means per se" for revoking
  consent via text: **STOP, QUIT, END, REVOKE, OPT OUT, CANCEL,
  UNSUBSCRIBE**
- Revocation via SMS applies to both **robotexts and robocalls**
  from that caller
- Revocation via SMS does **not** apply to email (governed by
  CAN-SPAM) or live human calls

### "Revoke all" provision (delayed)

The original rule required that an opt-out from one type of message
(e.g. appointment reminders) must revoke consent for **all** future
robocalls and robotexts from that caller on unrelated matters. This
is the "revoke all" provision.

**Current status:** The FCC has delayed this provision twice:
- First delay: April 11, 2025 to April 11, 2026
  ([DA-25-312](https://docs.fcc.gov/public/attachments/DA-25-312A1.pdf))
- Second delay: April 11, 2026 to **January 31, 2027**
  ([DA-26-12](https://docs.fcc.gov/public/attachments/DA-26-12A1.pdf))

The FCC is reconsidering this provision in an open rulemaking. It may
be narrowed or modified before taking effect.

**What this means for the module:** Currently, a STOP in response to
an appointment reminder only revokes consent for that type of
communication. When/if the "revoke all" provision takes effect, a
single STOP would revoke consent for all automated messaging from
the organization. The module should be prepared for either outcome.

### Opt-out scope summary (as of March 2026)

| Patient action | Blocks | Does not block |
|----------------|--------|----------------|
| Texts STOP | Automated texts and prerecorded calls from this sender | Email, patient portal, live calls, mail |
| Revoke all (not yet in effect) | All automated texts and calls from the organization | Email, portal, live calls, mail |

## HIPAA

The HIPAA Privacy Rule (45 CFR 164) and Security Rule govern how
PHI is handled in patient communications.

### Key requirements for SMS messaging

| Requirement | CFR | How the module addresses it |
|-------------|-----|----------------------------|
| Minimum necessary | 45 CFR 164.502(b) | Message templates should contain only the minimum PHI needed |
| Patient communication preferences | 45 CFR 164.522(b) | Patients may request confidential communications by alternative means; if reasonable, the provider must honor it |
| Transmission security | 45 CFR 164.312(e)(1) | SMS is inherently not encrypted end-to-end; see risk discussion below |
| Business associate agreement | 45 CFR 164.308(b) | Required with Sinch; see [Sinch HIPAA page](https://www.sinch.com/hipaa/) |
| Access controls | 45 CFR 164.312(a) | Module respects OpenEMR's ACL system |

### SMS and PHI risk

Standard SMS is not encrypted in transit. The HIPAA Security Rule
does not prohibit unencrypted channels outright, but requires a risk
assessment. Under 45 CFR 164.522(b), if a patient requests
communication via SMS and is informed of the risks, the provider
may comply.

**Practical guidance:**
- Keep message content minimal (e.g. "You have an appointment
  tomorrow at 2pm" rather than diagnosis details)
- Do not include diagnosis codes, test results, or treatment details
  in SMS
- Use the patient portal for PHI-rich communications
- Document patient consent to receive SMS in the medical record
  (this is what `hipaa_allowsms` represents)

### Sinch as a business associate

Sinch offers a BAA as an addendum to their Terms of Service. A BAA
must be executed before transmitting any PHI through the Sinch API.
Sinch's HIPAA compliance has been validated by a third party.

**References:**
- [Sinch HIPAA Compliance](https://www.sinch.com/hipaa/)
- [HHS: Business Associate Contracts](https://www.hhs.gov/hipaa/for-professionals/covered-entities/sample-business-associate-agreement-provisions/index.html)
- [45 CFR 164.312 (Security Rule technical safeguards)](https://www.law.cornell.edu/cfr/text/45/part-164/subpart-C)

## CAN-SPAM

The CAN-SPAM Act (15 U.S.C. 7701) governs commercial email. It does
**not** apply to SMS. It is noted here only to clarify that an SMS
opt-out does not extend to email, and email opt-out rules are a
separate concern.

## Carrier and Aggregator Requirements

Independent of TCPA and HIPAA, mobile carriers and messaging
aggregators (including Sinch) enforce their own policies:

- **STOP enforcement at the carrier level:** When a subscriber texts
  STOP to a short code or long code, the carrier may block further
  messages from that sender at the network level, regardless of what
  the application does. The module's opt-out handling reflects
  reality — the messages would not be delivered anyway.

### Sinch consent management and SMS STOP delivery

Sinch's Conversation API has two consent management mechanisms that
behave differently depending on the channel:

**OPT_OUT / OPT_IN webhook callbacks:** These fire for channels with
native consent support (e.g. Viber Business Messages). They do
**not** fire for SMS. Per Sinch docs: "Opt-in and opt-out callbacks
are only supported on Conversation API channels that support opt-in
and opt-out notification types."

**SMS STOP keywords:** On the SMS channel, STOP messages arrive as
regular `MESSAGE_INBOUND` webhook events. The module's
`KeywordHandlerService` detects these and calls
`ConsentService::optOut()`. This is the correct approach for SMS.

| Channel | How STOP arrives | Module handler |
|---------|-----------------|----------------|
| SMS | MESSAGE_INBOUND | KeywordHandlerService |
| Viber BM, others | OPT_OUT callback | WebhookController::handleOptOut() |

Sinch also has a "consent management mode" (Primary vs Secondary)
that controls whether Sinch blocks outbound messages to opted-out
recipients:
- **Primary:** Sinch blocks future messages. The app still receives
  the opt-out notification.
- **Secondary:** Sinch does not block. The app must enforce opt-out
  itself (which this module does via `MessageService` consent gating).
- **10DLC registration:** Long-code SMS campaigns must be registered
  under the 10DLC framework. Healthcare is a recognized use case
  with favorable throughput limits.
- **Message content filtering:** Carriers may filter messages that
  appear to contain prohibited content (SHAFT categories). Healthcare
  messages are generally exempt but poorly-worded messages can still
  trigger filters.

## How the Module Maps to These Requirements

| Module component | Regulatory requirement |
|-----------------|----------------------|
| `hipaa_allowsms` check | TCPA prior express consent; HIPAA patient communication preference |
| `ConsentService` opt-in/opt-out | TCPA consent tracking; carrier STOP compliance |
| `MessageService` consent gating | Prevents sending to patients who have revoked consent |
| `skip_consent_check` for keyword responses | TCPA-required STOP/HELP confirmations must be delivered |
| `skip_consent_check` for opt-in confirmations | Initial opt-in confirmation sent before consent record exists |
| `WebhookController` OPT_OUT/OPT_IN handlers | Sync consent from Sinch-managed channels (Viber BM, etc.) |
| `KeywordHandlerService` STOP/START detection | SMS opt-out (OPT_OUT callback does not fire for SMS) |
| Message templates | Support HIPAA minimum necessary principle; enforce 160-char limit per TCPA exemption |
| Sinch BAA | HIPAA business associate requirement |

## Open Questions

These are unresolved design questions with regulatory implications:

1. **Frequency enforcement:** The TCPA healthcare exemption limits
   messages to 1/day and 3/week. The module does not currently
   enforce this. Should it?

2. **Character limit enforcement:** The exemption requires 160
   characters or less. Should the module enforce or warn?

3. **Fallback channels:** When a patient opts out of SMS, how should
   the system notify staff to use an alternative channel for critical
   communications (portal, phone, mail)?

4. **"Revoke all" preparation:** If the FCC's revoke-all provision
   takes effect, a STOP on one message type revokes all automated
   messaging. The module currently tracks opt-out at the
   phone-number level, which aligns with this. But organizational
   systems that send from multiple numbers or through multiple
   vendors would need coordination.

5. **PHI in messages:** Should the module enforce content restrictions
   (no diagnosis codes, no billing info) or leave this to the
   template author?
