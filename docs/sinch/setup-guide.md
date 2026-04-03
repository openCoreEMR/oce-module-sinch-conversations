# Sinch Dashboard Setup Guide

This guide walks through setting up the Sinch Conversations API for SMS
messaging, from account configuration through 10DLC campaign registration.

## Prerequisites

- A Sinch account with access to the [Build Dashboard](https://dashboard.sinch.com/)
- A US business entity (EIN required for 10DLC registration)

## Account Structure

The Sinch Build Dashboard uses a hierarchical project structure:

- **Parent project** — Owns brands, campaigns, and billing
- **Subprojects** — Per-environment configuration (e.g., dev, staging, production)

## Step 1: Create a Conversations API App

1. Navigate to **Conversation API > Apps**
2. Create a new app with a descriptive name
3. Configure channels (SMS is the primary channel for this module)
4. Note the **App ID** — you need it for module configuration

## Step 2: Purchase a Phone Number

1. Navigate to **Numbers > Get virtual numbers**
2. Search for US 10DLC numbers
3. Purchase the number
4. The number appears under **Numbers > Your virtual numbers**

## Step 3: Link Number to SMS Service Plan

1. Navigate to **SMS > Service APIs**
2. Verify the number is assigned to an SMS service plan
3. The service plan callback URL should route to the Conversations API adapter

## Step 4: Configure Module Credentials

See [Module Configuration](#module-configuration) below for connecting
the Sinch app to OpenEMR.

---

## 10DLC Campaign Registration

US carriers require A2P (Application-to-Person) SMS from 10-digit numbers
to be registered through The Campaign Registry (TCR). Without registration,
carriers reject messages with **Error 310: Invalid source address**.

### Prerequisites

1. A 10-digit US phone number (purchased above)
2. Reseller status set (see Step A below)
3. A registered brand (see Step B below)

### Step A: Set Reseller Status

Navigate to **SMS > US 10DLC Campaigns**. A yellow banner prompts you
to set your reseller status:

- **No** — Direct sender or SaaS company sending on its own behalf
- **Yes** — Reseller registering brands and campaigns on behalf of customers

### Step B: Register a Brand

Navigate to **Numbers > Supporting Documentation > New brand**.

1. Select **Country: United States**, **Number Type: 10DLC**
2. Choose a registration type:

| | Simplified | Full |
|---|---|---|
| **Speed** | ~5 minutes | 5-10 days |
| **Vetting** | No vetting score | Vetting and vetting score |
| **Throughput** | Lower | Higher |
| **Numbers** | Max 3 | Unlimited |
| **Campaigns** | Limited | Unlimited |

For production use with multiple numbers, **Full** registration is
recommended. Refer to the
[Sinch 10DLC documentation](https://community.sinch.com/t5/10DLC/ct-p/10DLC)
for current pricing.

3. Fill in business details (EIN, legal name, address, etc.)
4. Submit for review (5-10 day turnaround for Full registration)

### Step C: Create a Campaign

Navigate to **SMS > US 10DLC Campaigns > Create new campaign**.

The campaign wizard has 8 steps:

1. **Select numbers** — Associate 10DLC numbers (can skip and assign later)
2. **Select brand** — Required; cannot proceed without a registered brand
3. **Select use case** — Campaign purpose (must not be a prohibited use case)
4. **Supporting documentation** — Upload compliance documents
5. **Campaign overview** — Provide a detailed campaign/service description
6. **Message flow and sample messages** — Include confirmation, opt-out
   ("Reply STOP"), help ("Reply HELP"), and sample messages
7. **Additional information** — Special attributes (age gate, direct lending,
   number pool)
8. **Review and finish** — Final review and submission

### Campaign Approval

Sinch reviews campaigns for CTIA 2023 Messaging Principles & Best Practices
alignment. Key requirements:

- **Opt-in method**: Describe how end users consent (website form,
  text-to-join, paper form)
- **Use case**: Must not be a prohibited category
- **Campaign description**: Detailed service description
- **Message samples**: Confirmation, opt-out, help, and sample messages
- **Privacy policy**: Must state that user information is not shared or
  sold to third parties

### Common Rejection Reasons

1. **Insufficient opt-in** — Did not describe how users consent. If consent
   is collected online, specify where on the website.
2. **Brand name mismatch** — Registered name does not match the
   website or message content.
3. **Insufficient privacy policy** — Does not clearly address data sharing
   practices.

---

## Module Configuration

The module supports two configuration modes:

### Option 1: Database Globals (default)

Configure via **Admin > Config > OpenCoreEMR Sinch Conversations Module** in the
OpenEMR admin interface.

### Option 2: Environment Variables

Set `OCE_SINCH_CONVERSATIONS_ENV_CONFIG=1` and provide credentials as
environment variables. This is useful for containerized deployments.

### Required Settings

| Setting | Env Var | Description |
|---------|---------|-------------|
| Project ID | `OCE_SINCH_CONVERSATIONS_PROJECT_ID` | Sinch project ID |
| App ID | `OCE_SINCH_CONVERSATIONS_APP_ID` | Conversations API app ID |
| API Key | `OCE_SINCH_CONVERSATIONS_API_KEY` | API key from Sinch dashboard |
| API Secret | `OCE_SINCH_CONVERSATIONS_API_SECRET` | API secret from Sinch dashboard |
| Region | `OCE_SINCH_CONVERSATIONS_REGION` | `us` or `eu` |
| Default Channel | `OCE_SINCH_CONVERSATIONS_DEFAULT_CHANNEL` | `SMS` (default) |
| Clinic Name | `OCE_SINCH_CONVERSATIONS_CLINIC_NAME` | Display name in messages |
| Clinic Phone | `OCE_SINCH_CONVERSATIONS_CLINIC_PHONE` | Sender phone number (E.164 format, e.g., +12085551234) |

### Verifying Configuration

After configuring the module:

1. Log out and log back in (menu updates require re-login)
2. Navigate to **Modules > OpenCoreEMR Sinch Conversations**
3. Verify settings display correctly
4. Send a test SMS

> **Note:** If using a test short code (e.g., 10907), messages bypass
> 10DLC requirements. With a real number, a registered campaign is
> required for delivery.
