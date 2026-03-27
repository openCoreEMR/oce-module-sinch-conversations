# Testing Scenarios

This document describes end-to-end testing scenarios for the messaging
functionality. Run these tests after configuring the module
(see [Setup Guide](../sinch/setup-guide.md)).

## Scenario 1: Appointment Reminder (Patient Opted In)

**Setup:**

1. Ensure the patient chart has **Allow SMS** set to Yes
2. Set **Admin > Config > Notifications > SMS Notification Hours**
   to a test-appropriate value
3. Schedule an appointment for the patient within the notification window

**Expected result:**

- The patient receives an SMS reminder at the configured lead time
- The message content matches the appropriate template (portal-enabled
  or portal-disabled variant)
- The message starts with "OpenCoreEMR" and ends with opt-out text

## Scenario 2: Opt Out via Text

**Setup:**

1. Patient has received at least one message from the module

**Steps:**

1. Patient replies with one of: STOP, QUIT, END, CANCEL, UNSUBSCRIBE
2. The module receives the keyword via `MESSAGE_INBOUND` webhook

**Expected result:**

- The patient's **Allow SMS** field is set to No in the chart
- The `ConsentService` records an opt-out
- The patient does not receive further appointment reminders
- Subsequent appointment scheduling for this patient does not trigger SMS

## Scenario 3: Opt Out via Patient Chart

**Setup:**

1. Patient has **Allow SMS** set to Yes and has been receiving messages

**Steps:**

1. Navigate to the patient chart
2. Set **Allow SMS** to No

**Expected result:**

- The patient does not receive further appointment reminders
- No webhook interaction is required — the chart setting alone blocks sending

## Scenario 4: Appointment Reminder (Patient Opted Out)

**Setup:**

1. Patient chart has **Allow SMS** set to No (opted out via either method)
2. Schedule an appointment for the patient

**Expected result:**

- No SMS is sent to the patient
- The module skips the patient during the reminder check

## Scenario 5: Portal-Enabled vs Portal-Disabled Message

**Steps:**

1. Enable the patient portal (**Admin > Config > Portal**)
2. Trigger an appointment reminder
3. Verify the message includes the portal link
4. Disable the patient portal
5. Trigger another appointment reminder
6. Verify the message does **not** include a portal link

**Expected result:**

- Portal-enabled message includes the portal URL and
  reschedule/cancel instructions
- Portal-disabled message contains only the appointment time and opt-out text
