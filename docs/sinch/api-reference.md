# Sinch API Documentation

## For AI Agents

When working with Sinch APIs (Conversations, SMS, etc.), reference the official documentation:

1. **Check local copy first**: `.local/llms.txt` (cached copy of Sinch API docs)
2. **Fetch latest if needed**: https://developers.sinch.com/llms.txt

The `llms.txt` file contains comprehensive API documentation for:
- Sinch Conversations API
- Message formats and types
- Webhook events
- Authentication
- Error handling

## Conversations API (Primary for this module)

**Documentation:** https://developers.sinch.com/docs/conversation/api-reference/

**Use for:**
- Sending/receiving messages across channels (SMS, WhatsApp, etc.)
- Managing conversations and contacts
- Handling webhooks for message events
- Managing message templates

## Additional Sinch APIs (not in llms.txt)

**Provisioning & Management APIs:**

- **Subproject API**: For managing subprojects within a Sinch project
  - Docs: https://developers.sinch.com/docs/subproject/api-reference/subproject.md
  - Use for: Multi-tenant setups, organizational hierarchy, resource isolation
  - Operations: Create, list, get, update, delete subprojects
  - Not currently documented in llms.txt - consult web docs directly

- **Access Keys API**: For managing API keys and access control
  - Docs: https://developers.sinch.com/docs/accesskeys/api-reference.md
  - Use for: Creating/revoking API keys, managing permissions, scopes
  - Operations: Create keys, list keys, revoke keys, manage scopes
  - Essential for provisioning automation and multi-tenant setups
  - Not currently documented in llms.txt - consult web docs directly

- **Projects API**: For managing Sinch projects
  - Docs: https://developers.sinch.com/docs/account/projects.md
  - Use for: Project configuration, settings management
  - Operations: Get project details, update settings
  - Not currently documented in llms.txt - consult web docs directly

## When to Use

| Task | API |
|------|-----|
| Implementing API integrations | Conversations API |
| Understanding webhook payloads | Conversations API |
| Debugging API responses | Conversations API |
| Adding new messaging features | Conversations API |
| Managing subprojects and resource organization | Subproject API |
| Managing API keys and permissions | Access Keys API |
| Project configuration | Projects API |

## When Implementing Provisioning Features

1. Check llms.txt for Conversations API details (messages, contacts, webhooks)
2. Consult web docs (markdown format) for Access Keys, Subprojects, and Projects APIs
3. Use `AppConfigurationClient` pattern for new provisioning methods
4. Add corresponding CLI commands for automation
5. Follow existing command patterns (environment vars, options, error handling)
