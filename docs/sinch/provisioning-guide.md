# Sinch App Provisioning Guide

This guide walks through the complete provisioning workflow for a Sinch Conversation API app, from account prerequisites through verification. Follow these steps when setting up the module for a new tenant or from scratch.

For 10DLC registration, phone number purchase, and module credential configuration, see [setup-guide.md](setup-guide.md).

For detailed API behavior and empirical findings, see [consent-api-findings.md](consent-api-findings.md).

## Prerequisites

Before provisioning the Conversation API app, confirm:

- A Sinch account with Conversation API access on the [Build Dashboard](https://dashboard.sinch.com/)
- A 10DLC brand and campaign registered and approved (see [setup-guide.md](setup-guide.md#10dlc-campaign-registration))
- A US phone number purchased and linked to an SMS service plan (see [setup-guide.md](setup-guide.md#step-2-purchase-a-phone-number))

## Step 1: Create or Configure a Conversation API App

Navigate to **Conversation API > Overview** in the Sinch dashboard. Click **Create app** to create a new app, or click an existing app to configure it.

Give the app a descriptive name that identifies the tenant or environment (e.g., `oce-prod-clinic-name`).

Note the **App ID** displayed on the app details page. The module configuration requires this value.

New apps default to DISPATCH processing mode. Older apps may still be in CONVERSATION mode.

> [!IMPORTANT]
> **Credential scope matters.** The API credentials (Project ID, API Key, API Secret) must belong to the same Sinch project that owns the app. If your Sinch account has subprojects, credentials from a subproject cannot access apps in the parent project (or vice versa). The API will authenticate successfully but silently fail to find the app. See [consent-api-findings.md](consent-api-findings.md#credential-configuration) for details.

## Step 2: Switch to DISPATCH Mode

This module is a message dispatch agent: it sends outbound SMS (appointment reminders, notifications) and does not need Sinch's conversation storage or state management. DISPATCH mode avoids the overhead of server-side conversation tracking.

Navigate to the app details page, click **Edit**, and set **Processing mode** to **DISPATCH**.

Alternatively, switch the mode via the API:

```
PATCH /v1/projects/{project_id}/apps/{app_id}
Content-Type: application/json

{"processing_mode": "DISPATCH"}
```

See [ADR-0001](../adr/0001-dispatch-mode-consent-management.md) for the architectural rationale behind choosing DISPATCH mode over CONVERSATION mode.

## Step 3: Enable Consent Management

Consent Management configures Sinch to process STOP/START keywords from inbound messages and exposes the Consent API for querying opt-out status. Without this step, all Consent API calls return 404.

> [!WARNING]
> **Consent Management does NOT block sends via the Conversation API.** Despite the dashboard description stating Primary mode "will block" messages to opted-out numbers, live testing confirmed that messages sent via `POST /messages:send` are still delivered to opted-out recipients. The application must check the Consent API and enforce opt-out before every send. See [ADR-0001 RC-5](../adr/0001-dispatch-mode-consent-management.md#42-validation-progress) for the test evidence.

**Accepting terms requires the Sinch dashboard.** However, enabling the mode itself can also be done via API after the initial T&C acceptance:

```
PATCH /v1/projects/{project_id}/apps/{app_id}
Content-Type: application/json

{"consent_manager_settings": {"mode": "PRIMARY"}}
```

Dashboard path for initial enablement:

1. Navigate to the app details page.
2. Scroll to the **Consent management** section.
3. Click **Edit consent management**.
4. Accept the terms at [sinch.com/legal/conversation-api-old/consent-management-feature](https://sinch.com/legal/conversation-api-old/consent-management-feature/).
5. Choose **Primary** processing mode.

The Consent Management service is free of charge (the T&C states "the Service is free of charge"), though the dashboard shows an "Additional fees/message" badge. The auto-reply messages (STOP/START confirmations) are charged as normal outbound SMS. Clarify the badge meaning with Sinch if needed.

## Step 4: Add CTIA-Required Keywords

The default English keyword set only includes "Stop" (opt-out) and "Start" (opt-in). CTIA Messaging Principles require honoring additional keywords:

Navigate to the app's Consent Management page > **Keywords** section > click the **⋮** menu on the English row > **Edit**.

Add these keywords:

| Type | Default | Add these |
|------|---------|-----------|
| Opt-out | Stop | End, Cancel, Quit, Unsubscribe |
| Opt-in | Start | Unstop, Subscribe |

Click **Update** to save. Sinch keyword matching is case-insensitive and exact-match only (the keyword must be the entire message body, not a substring).

## Step 5: Configure SMS Channel

1. Navigate to the app details page.
2. Click **Set up channels** and select **SMS**.
3. Link the SMS service plan to the app. Select the service plan associated with the 10DLC phone number.
4. Verify the channel shows as **Active**.

The SMS channel connects the Conversation API app to the underlying SMS service plan, routing outbound messages through the registered 10DLC number.

## Step 6: Set Default Originator

> [!IMPORTANT]
> **Without a Default Originator, messages are silently dropped.** The Sinch API accepts the message (returns 200 OK) but the message is never delivered. There is no error — the send appears to succeed. This is the most common cause of "messages not arriving" during initial setup.

Navigate to **SMS > Service APIs > [your service plan]** and scroll to **Default Originators**.

1. Click **Set Default Originators**.
2. Select **United States**.
3. Select the 10DLC campaign-registered phone number from the dropdown.
4. Click **Configure**.

The selected number must be assigned to a registered 10DLC campaign. Using an unregistered number causes carriers to silently reject the message (error 310: Invalid source address), which also produces no visible error in the Sinch API response.

## Step 7: Configure Webhooks (Optional)

With the query-on-demand consent checking approach (see [ADR-0001](../adr/0001-dispatch-mode-consent-management.md)), webhooks are optional for the core opt-out flow. The module queries the Consent API before each send rather than relying on webhook notifications. Webhooks provide faster notification of delivery events and inbound messages but are not required for compliance.

To configure webhooks:

1. Navigate to the app details page.
2. Scroll to the **Webhooks** section and click **Add webhook**.
3. Set **Target Type** to **HTTP**.
4. Set **Target URL** to the module's webhook endpoint:
   ```
   https://your-domain/interface/modules/custom_modules/oce-module-sinch-conversations/public/webhook.php
   ```
5. Set **Secret token** to a shared secret for HMAC-SHA256 signature validation.
6. Select triggers: **MESSAGE_DELIVERY**, **MESSAGE_INBOUND**, **OPT_IN**, **OPT_OUT**.

**Important:** Sinch rejects Basic Auth credentials embedded in webhook URLs (e.g., `https://user:pass@host/path`). Use the secret token for authentication instead.

Configure the same secret in the module's settings at **Admin > Config > OpenCoreEMR Sinch Conversations Module > Webhook Secret**.

## Step 8: Configure the OpenEMR Module

Set the following values via **Admin > Config > OpenCoreEMR Sinch Conversations Module** or environment variables:

| Setting | Env Var | Description |
|---------|---------|-------------|
| Project ID | `OCE_SINCH_CONVERSATIONS_PROJECT_ID` | Sinch project ID |
| App ID | `OCE_SINCH_CONVERSATIONS_APP_ID` | Conversation API app ID (from Step 1) |
| API Key | `OCE_SINCH_CONVERSATIONS_API_KEY` | API key from Sinch dashboard |
| API Secret | `OCE_SINCH_CONVERSATIONS_API_SECRET` | API secret from Sinch dashboard |
| Region | `OCE_SINCH_CONVERSATIONS_REGION` | `us` or `eu` |
| Default Channel | `OCE_SINCH_CONVERSATIONS_DEFAULT_CHANNEL` | `SMS` (default) |
| Clinic Name | `OCE_SINCH_CONVERSATIONS_CLINIC_NAME` | Display name in messages |
| Clinic Phone | `OCE_SINCH_CONVERSATIONS_CLINIC_PHONE` | Sender phone number (E.164 format, e.g., +12085551234) |
| Webhook Secret | `OCE_SINCH_CONVERSATIONS_WEBHOOK_SECRET` | HMAC secret (if webhooks configured) |

See [setup-guide.md](setup-guide.md#module-configuration) for details on configuration modes (database globals vs. environment variables).

## What Can Be Automated via API

| Step | Automatable? | API | Notes |
|------|-------------|-----|-------|
| Create app | Yes | `POST /v1/projects/{project_id}/apps` | |
| Switch processing mode | Yes | `PATCH /v1/projects/{project_id}/apps/{app_id}` | |
| Enable Consent Management | **Partial** | `PATCH {"consent_manager_settings": {"mode": "PRIMARY"}}` | T&C acceptance requires dashboard; mode toggle works via API after initial acceptance |
| Add CTIA keywords | Unknown | — | Not yet tested via API; dashboard works |
| Set Default Originator | Unknown | — | Not yet tested via API; dashboard works |
| Configure SMS channel | Partial | — | Channel linking may require dashboard |
| Create webhook | Yes | `POST /v1/projects/{project_id}/apps/{app_id}/webhooks` | |
| Query consent status | Yes | `GET /consents/OPT_OUT_ALL` | Read-only; cannot add/remove numbers via API |
| Send messages | Yes | `POST /messages:send` | |

The Consent Management T&C acceptance is the only step that strictly requires manual dashboard interaction for the initial setup. Subsequent mode changes can be automated.

## Local Development

Use the following Taskfile commands for local webhook development:

| Command | Description |
|---------|-------------|
| `task webhook:tunnel` | Start a Tailscale Funnel to expose the local webhook endpoint |
| `task webhook:tunnel:status` | Show funnel status and the public webhook URL |
| `task webhook:tunnel:off` | Stop the Tailscale Funnel |
| `task webhook:url` | Show the local webhook URL |

The tunnel creates a public HTTPS endpoint that Sinch can deliver webhooks to during development.

## Verifying the Setup

### Test consent API availability

Use the CLI to verify the app is accessible and Consent Management is active:

```bash
SINCH_API_SECRET=... php cli.php sinch:consent:check \
  --app-id=... --project-id=... --api-key=... --region=us
```

This command tests app accessibility, Consent API availability, and processing mode. Expected results:

- **App Config: PASSED** with `mode=DISPATCH` — app is accessible and in the correct mode
- **List Opt-Outs: PASSED** with 0 entries — Consent API endpoint is reachable (empty list is normal for a new app)
- If the Consent API returns 404, Consent Management has not been enabled (see Step 3)

Note: `SINCH_API_SECRET` must be provided via environment variable, not as a CLI option.

### Test outbound messaging

1. Send a test message via the Sinch dashboard: app details page > **Send test messages**.
2. If the test message arrives, the SMS channel and 10DLC campaign are correctly linked.
3. If not, check: Default Originator set (Step 6)? Campaign approved? Number assigned to campaign?

> [!NOTE]
> The Sinch API returns 200 (accepted) for every send, even when the message will never be delivered. A 200 response does not mean the message was delivered. Check the Sinch dashboard **SMS > Message search** for delivery status.

### Test opt-out flow

1. Send a message to your phone number.
2. Reply **STOP** — confirm you receive the auto-reply ("You have opted out of all communications...").
3. Query the consent API: `GET /consents/OPT_OUT_ALL` should include your number (without the `+` prefix).
4. Reply **START** — confirm you receive the opt-in auto-reply.
5. Query again — your number should be removed from the list.

If using a test short code (e.g., 10907), messages bypass 10DLC requirements. With a real 10DLC number, the campaign must be approved before carriers will deliver messages.
