# OpenEMR Navigation

Guide for AI agents navigating OpenEMR's interface.

## Menu Structure

```
Top Menu Bar:
├── Calendar
├── Finder
├── Flow
├── Recalls
├── Messages
├── Patient
├── Fees
├── Modules
│   ├── Manage Modules
│   ├── Carecoordination
│   └── OpenCoreEMR Sinch Conversations  ← Main module entry
├── Procedures
├── Admin
│   ├── Config  ← Module settings here
│   │   └── OpenCoreEMR Sinch Conversations Module
│   ├── Clinic
│   ├── Patients
│   ├── Practice
│   ├── Coding
│   ├── Forms
│   ├── Documents
│   ├── System
│   ├── Users
│   ├── Address Book
│   └── ACL
├── Reports
├── Miscellaneous
└── Popups
```

## Key Navigation Paths

### Accessing the Module

1. Click "Modules" in top menu
2. Hover to reveal dropdown
3. Click "OpenCoreEMR Sinch Conversations"
4. Module loads in iframe tab

### Accessing Module Settings

1. Click "Admin" in top menu
2. Hover to reveal dropdown
3. Click "Config"
4. Scroll down to "OpenCoreEMR Sinch Conversations Module" section

## Navigation Tips for AI Agents

### Iframe Handling

OpenEMR uses iframes extensively:
- Main content loads in an iframe
- Modules load in tabbed iframes
- Dialogs open in iframe overlays

**Always:**
- Wait 2-3 seconds after navigation
- Check which iframe contains the target content
- Use `read_page` to find elements in current context

### Dropdown Menus

Menu dropdowns require hover:

```
1. Find menu item (e.g., "Modules")
2. computer action=hover coordinate=[x, y]
3. Wait for dropdown to appear
4. Find submenu item
5. computer action=left_click coordinate=[x, y]
```

### Modal Dialogs

OpenEMR uses Bootstrap modals:
- Wait for modal animation
- Target elements within modal context
- Close button typically in top-right corner

## Critical Testing Behaviors

### Module Enable/Disable Requires Re-login

**IMPORTANT:** When the module's enabled status changes (either via Admin > Config checkbox or environment variable), the user must **log out and log back in** for the change to take effect.

This is because:
- The menu system reads the module's enabled global at login time
- Changing the enabled setting doesn't update the current session's menu
- The module menu item only appears after a fresh login with the setting enabled

**Testing workflow:**
1. Enable module in Admin > Config > OpenCoreEMR Sinch Conversations
2. Click Save
3. Log out (user menu > Logout)
4. Log back in
5. Module now appears in Modules menu

### Never Navigate Directly to URLs

**CRITICAL:** Never navigate directly to OpenEMR URLs. Always use the menu system.

OpenEMR uses session tokens in URLs. Direct navigation:
- Bypasses the tab/iframe system
- Can trigger alerts or security errors
- Breaks the navigation state

**Always:**
- Navigate via menu clicks
- Close tabs using the X button
- Re-open pages through the menu

## Common Navigation Issues

**Issue: Menu item not clickable**
- Menu may need hover first to reveal dropdown
- Wait for animations to complete

**Issue: Content not loading after click**
- Content loads in iframe - wait 2-3 seconds
- Use `read_page` to verify content loaded

**Issue: Tab/iframe context wrong**
- Use `tabs_context_mcp` to verify current tab
- Content may be in nested iframe

**Issue: Settings not saving**
- Look for "Save" or "Submit" button
- Check for validation errors
- Verify CSRF token is present

**Issue: Module not appearing in menu**
- Check if module is enabled in Admin > Config
- Log out and log back in after enabling
- Verify `OCE_SINCH_CONVERSATIONS_ENABLED=1` if using env config

**Issue: Page refresh needed but can't refresh**
- Close the current tab (click X on the tab)
- Re-navigate through the menu
- Never use browser refresh (Cmd+R) in OpenEMR

## URL Patterns

| Page | URL Pattern |
|------|-------------|
| Login | `/interface/login/login.php` |
| Main Interface | `/interface/main/tabs/main.php` |
| Global Config | `/interface/super/edit_globals.php` |
| Module Manager | `/interface/modules/zend_modules/public/` |
| Conversations Module | `/interface/modules/custom_modules/oce-module-sinch-conversations/public/index.php` |
| Module Settings | `/interface/modules/custom_modules/oce-module-sinch-conversations/public/settings.php` |

## Finding Module in Menu

1. Use `read_page` to get accessibility tree
2. Look for text "Modules" in menu items
3. Click to expand dropdown
4. Find "OpenCoreEMR Sinch Conversations"
5. Click to open module tab

## Best Practices

1. **Always get context first** - Use `tabs_context_mcp`
2. **Use element references** - Prefer `ref_*` over coordinates
3. **Wait after navigation** - Allow 2-3 seconds for iframe loading
4. **Handle dropdowns properly** - Hover before clicking submenu items
5. **Check for dialogs** - Modal dialogs can block interaction
6. **Verify success** - Take screenshot or read_page after actions
