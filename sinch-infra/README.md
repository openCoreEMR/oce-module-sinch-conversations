# Sinch Infrastructure Tooling

Infrastructure-as-code and CLI tools for managing Sinch Conversations API configuration.

## Directory Structure

```
sinch-infra/
├── README.md           # This file
├── inspect.php         # CLI tool to inspect Sinch configuration
├── terraform/          # Terraform modules (future)
└── scripts/            # Helper scripts
```

## Quick Start

### 1. Inspect Current Configuration

```bash
php inspect.php
```

This will show:
- Project and App details
- Configured channels (SMS, WhatsApp, etc.)
- Sender numbers/identities
- App mode (DISPATCH, CONVERSATION)
- Channel states

### 2. Environment Variables

The tools read from OpenEMR's globals configuration automatically, or you can override with environment variables:

```bash
export SINCH_PROJECT_ID="your-project-id"
export SINCH_APP_ID="your-app-id"
export SINCH_API_KEY="your-api-key"
export SINCH_API_SECRET="your-api-secret"
export SINCH_REGION="us"  # or "eu"
```

## Terraform (Future)

Currently, there is no official Terraform provider for Sinch. We can either:

1. **Use HTTP/REST API provider** - Manage resources via raw API calls
2. **Wait for official provider** - Monitor https://registry.terraform.io
3. **Build custom provider** - If there's demand

For now, use the CLI inspection tool to understand your current setup, then configure manually via Sinch dashboard.

## CLI Tools

### inspect.php

Fetches and displays your Sinch configuration:

```bash
php inspect.php

# Output:
# ========================================
# Sinch Configuration Inspector
# ========================================
#
# Project ID: a0a2c92d-e46f-471a-b2c3-bff0d0eea3ef
# Region: us
#
# App Details:
#   ID: 01KA1YY5DXF6M6H7AJRE6J2112
#   Name: My App
#   Mode: DISPATCH
#
# Channels:
#   SMS:
#     State: ACTIVE
#     Sender: +1234567890
#   WHATSAPP:
#     State: PENDING
```

## Next Steps

1. Run `php inspect.php` to see your current configuration
2. Identify what needs to be configured (channels, senders, etc.)
3. Configure via Sinch dashboard or API scripts
4. Document the desired state for future IaC implementation
