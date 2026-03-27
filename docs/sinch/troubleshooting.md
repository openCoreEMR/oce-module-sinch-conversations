# Troubleshooting

This guide covers common issues when sending SMS through the Sinch
Conversations API and how to debug them.

## Understanding Delivery Status

The Sinch Conversation API returns HTTP 200 when it accepts a message
for delivery. This does **not** mean the SMS was delivered — delivery
is asynchronous. The Sinch dashboard is the definitive source for
delivery status.

## Debugging Undelivered SMS

Follow these steps to investigate why a message was not delivered:

1. **Check analytics** — Navigate to **Conversation API > Analytics**
   and look at the "Failed messages" tab for failure counts
2. **Search for the message** — Navigate to **SMS > Message search**
   and search by date range to find the specific message
3. **View message details** — Click "View" to see the error code,
   description, and troubleshooting tips

## Common Errors

| Error | Meaning | Fix |
|-------|---------|-----|
| **310 (Invalid source address)** | No 10DLC campaign registered for the sender number | Register a 10DLC brand and campaign (see [Setup Guide](setup-guide.md#10dlc-campaign-registration)) |
| **"Sinch API is not fully configured"** | One or more required configuration values are missing | Verify all required settings in Admin > Config (see [Module Configuration](setup-guide.md#module-configuration)) |

## Dashboard Navigation Reference

| Task | Path in Sinch Dashboard |
|------|------------------------|
| Set reseller status | SMS > US 10DLC Campaigns (yellow banner) |
| Register a brand | Numbers > Supporting Documentation > New brand |
| Create a campaign | SMS > US 10DLC Campaigns > Create new campaign |
| Check campaign status | SMS > US 10DLC Campaigns > Requests tab |
| Debug failed messages | SMS > Message search |
| View delivery analytics | Conversation API > Analytics |
| Manage numbers | Numbers > Your virtual numbers |
| View Conversation API apps | Conversation API > Apps |

## Module-Level Troubleshooting

### Menu item does not appear after enabling the module

Log out and log back in. OpenEMR caches the menu structure per session.

### Messages accepted but never delivered

1. Check that the sender number has an active 10DLC campaign
2. Verify the recipient number is a valid US mobile number
3. Check the Sinch dashboard for carrier-level rejections
4. Review the patient's opt-out status — the module blocks messages
   to patients who have opted out

### Opt-out webhook not firing

For SMS, STOP messages arrive as `MESSAGE_INBOUND` webhook events,
not `OPT_OUT` callbacks. The `OPT_OUT` callback type only fires for
channels with native consent support (e.g., Viber Business Messages).
See [Regulatory Considerations](../regulatory.md#sinch-consent-management-and-sms-stop-delivery)
for details.
