# OpenEMR Login Process

Guide for AI agents performing browser automation with OpenEMR.

## Login URL

```
{openemr_base_url}/interface/login/login.php
```

## Default Credentials

| Field | Value |
|-------|-------|
| Username | `admin` |
| Password | `pass` |

## Login Form Elements

```
Username field: input[name="authUser"]
Password field: input[name="clearPass"]
Login button: button[type="submit"]
```

## Login Process

1. Navigate to login URL
2. Wait for page to load completely
3. Enter username in `authUser` field
4. Enter password in `clearPass` field
5. Click the login button
6. Wait for redirect to main interface

## After Login

After successful login, OpenEMR redirects to:
```
/interface/main/tabs/main.php
```

This is the main tabbed interface where modules load.

## Common Issues

**Issue: Login form not visible**
- Wait for page to fully load
- Check if already logged in (redirects to main.php)

**Issue: Login fails**
- Verify credentials are correct
- Check for CSRF token issues
- Look for error messages on page

**Issue: Redirect loop**
- Clear session/cookies
- Restart Docker container if needed

## Form Elements Reference

Using `read_page` tool, the login form typically shows:

```
ref_1: input type="text" name="authUser" (Username field)
ref_2: input type="password" name="clearPass" (Password field)
ref_3: select name="languageChoice" (Language selector)
ref_4: button type="submit" (Login button)
```

## Example Automation Flow

```
1. tabs_context_mcp -> Get tab ID
2. navigate -> /interface/login/login.php
3. read_page -> Find form elements
4. form_input ref=username_ref value="admin"
5. form_input ref=password_ref value="pass"
6. computer action=left_click ref=submit_button
7. computer action=wait duration=3
8. Verify redirect to main.php
```
