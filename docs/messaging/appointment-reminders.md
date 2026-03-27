# Appointment Reminders

Appointment reminders are the Phase 1 messaging use case for this module.
The module will send automated SMS reminders to patients ahead of their
scheduled appointments.

> **Status:** This feature is in active development (see #32). The
> message templates and configuration schema below describe the target
> design; the scheduling and sending logic is not yet merged.

## How It Works

1. An administrator configures the reminder lead time via
   **Admin > Config > Notifications > SMS Notification Hours**
2. The module checks for upcoming appointments within the configured window
3. For each eligible patient (opted in to SMS), the module sends a reminder
   via the Sinch Conversations API

## Admin Configuration

### SMS Notification Hours

Navigate to **Admin > Config > Notifications** and set **SMS Notification
Hours** to the number of hours before an appointment to send the reminder.

For example, setting this to `24` sends reminders 24 hours before each
appointment.

### Future Enhancements (not yet implemented)

- Multiple reminder intervals (e.g., 1 week, 3 days, 24 hours before)
- Customizable reminder text (within compliance constraints)

## Message Templates

### Template Variables

| Variable | Description | Phase 1 Required |
|----------|-------------|-----------------|
| `{{ clinic_name }}` | Practice display name (from module config) | Yes |
| `{{ appt_time }}` | Appointment date/time (e.g., "Saturday, Nov 15, 2025 at 2:30 PM EST") | Yes |
| `{{ opt_out }}` | Opt-out text: "Reply STOP to unsubscribe at any time." | Yes |
| `{{ portal_link }}` | Patient portal URL | No (used when portal is enabled) |
| `{{ phone }}` | Clinic phone number | No |

### Portal Enabled

When the patient portal is enabled (**Admin > Config > Portal**), the
reminder includes a link:

```
OpenCoreEMR: You have an upcoming appointment with {{ clinic_name }}
on {{ appt_time }}. For details, or to reschedule/cancel, please log
in to your patient portal: {{ portal_link }}. {{ opt_out }}.
```

### Portal Disabled

When the patient portal is not enabled:

```
OpenCoreEMR: You have an upcoming appointment with {{ clinic_name }}
on {{ appt_time }}. {{ opt_out }}.
```

### Message Format Requirements

- Messages must start with "OpenCoreEMR" (required by the registered
  campaign — the brand name must appear in the message)
- Messages must end with the opt-out text
- Keep total message length within 160 characters when possible
  (TCPA healthcare exemption limit; see
  [Regulatory Considerations](../regulatory.md#healthcare-exemption))

## Eligibility

A patient receives an appointment reminder only when **all** of the
following are true:

1. The patient has an upcoming appointment within the notification window
2. The patient's chart has **Allow SMS** set to Yes (`hipaa_allowsms`)
3. The patient has not opted out at the module level
   (`ConsentService` consent record)
4. The patient has a valid mobile phone number on file
