# Code Quality Standards

All code must pass these checks:

```bash
pre-commit run -a
```

This runs:
- PHP Syntax Check
- PHP_CodeSniffer (PHPCS)
- PHPStan Static Analysis
- Rector
- Composer Require Checker

## Common Quality Issues to Avoid

### Line Length
- Maximum 120 characters per line
- Split long constructors across multiple lines

### Type Hints
- Add PHPDoc for array parameters: `@param array<string, mixed> $params`
- Use proper return types on all methods

### Unused Code
- Never suppress warnings with `@SuppressWarnings`
- If a parameter is unused, either use it or remove it
- Remove commented-out code

## Security Checklist

- Always validate CSRF tokens on POST requests
- Check user authentication before sensitive operations
- Use `realpath()` and path validation to prevent directory traversal
- Sanitize all user input in templates (`text`, `attr` filters)
- Log security events (failed auth, path traversal attempts)
- Never expose detailed error messages to users

## Quick Checklist

Before considering work complete:

- [ ] Public entry points are 25-35 lines max
- [ ] Controllers use `Request::createFromGlobals()`
- [ ] No direct access to $_GET, $_POST, $_FILES, $_SERVER, $_SESSION
- [ ] Controllers return Response objects (never void)
- [ ] No `header()`, `http_response_code()`, `die()`, or `exit` calls
- [ ] Custom exception hierarchy with interface and getStatusCode()
- [ ] Twig templates for all HTML (no inline HTML in PHP)
- [ ] CSRF validation on all POST requests
- [ ] Redirects remove `action` parameter to prevent loops
- [ ] Responses for tabs/iframes set `X-Frame-Options: SAMEORIGIN`
- [ ] Dialog templates don't use `openemr_header_setup()`
- [ ] No CDN links - use `{{ webroot }}/public/assets/...` for all static assets
- [ ] All pre-commit checks passing
- [ ] PHPDoc comments with proper type hints
- [ ] Symfony HTTP Foundation components used throughout
