# Exception Handling Pattern

## Error Handling Best Practice: Always Catch `\Throwable`

**CRITICAL: Always catch `\Throwable` instead of `\Exception`**

- `\Throwable` is the base interface for both `\Exception` and `\Error`
- Catching `\Exception` will miss fatal errors like `\TypeError`, `\ParseError`, etc.
- Always use `catch (\Throwable $e)` for comprehensive error handling

**Example:**
```php
try {
    $this->service->doSomething();
} catch (\Throwable $e) {  // Catches both exceptions and errors
    $this->logger->error("Operation failed: " . $e->getMessage());
}
```

**Never do:**
```php
try {
    $this->service->doSomething();
} catch (\Exception $e) {  // Misses TypeError, ParseError, etc.
    $this->logger->error("Operation failed: " . $e->getMessage());
}
```

## Exception Hierarchy

This module defines exceptions in `src/Sinch/Conversation/Exception/`:

```php
<?php
// src/Sinch/Conversation/Exception/ExceptionInterface.php

namespace OpenCoreEMR\Sinch\Conversation\Exception;

interface ExceptionInterface extends \Throwable
{
    /**
     * Get the HTTP status code for this exception
     */
    public function getStatusCode(): int;
}
```

```php
<?php
// src/Sinch/Conversation/Exception/BaseException.php

namespace OpenCoreEMR\Sinch\Conversation\Exception;

abstract class BaseException extends \RuntimeException implements ExceptionInterface
{
    abstract public function getStatusCode(): int;
}
```

```php
<?php
// src/Sinch/Conversation/Exception/NotFoundException.php

namespace OpenCoreEMR\Sinch\Conversation\Exception;

class NotFoundException extends BaseException
{
    public function getStatusCode(): int
    {
        return 404;
    }
}
```

## Available Exception Types

| Exception | HTTP Status | Use Case |
|-----------|-------------|----------|
| `NotFoundException` | 404 | Resource not found |
| `UnauthorizedException` | 401 | User not authenticated |
| `AccessDeniedException` | 403 | CSRF failed, insufficient permissions |
| `ValidationException` | 400 | Invalid input data |
| `ConfigurationException` | 500 | Configuration errors |
| `ApiException` | 500 | Sinch API errors |

## Exception Handling in Public Files

```php
try {
    $response = $controller->dispatch($action);
    $response->send();
} catch (ExceptionInterface $e) {
    $logger->error("Module error: " . $e->getMessage());

    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage()),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Throwable $e) {
    $logger->error("Unexpected error: " . $e->getMessage());

    $response = new Response(
        "Error: An unexpected error occurred",
        500
    );
    $response->send();
}
```

## Don't Swallow Exceptions

**Never catch an exception just to log it and continue silently.** This hides failures where nobody will notice them.

```php
// Bad — failure disappears into the log
try {
    $this->sendResponse($phoneNumber, $response);
} catch (\Throwable $e) {
    $this->logger->error("Failed to send response: " . $e->getMessage());
}
```

When you catch an exception, the code must **do something meaningful** with it:

- **Re-throw** (possibly wrapped) if the caller should handle it
- **Return the error** so the caller can decide (e.g., collect failures and report them)
- **Degrade gracefully** with a user-visible indication that something went wrong

If none of these apply, let the exception propagate — that's the default for a reason.

The one legitimate use of catch-log-continue is at a **loop boundary** where one iteration's failure should not stop others, **and** the failures are collected and surfaced to the caller:

```php
// OK — failures collected and returned
$failures = [];
foreach ($messages as $message) {
    try {
        $this->process($message);
    } catch (\Throwable $e) {
        $this->logger->error("Failed to process {$message['id']}: " . $e->getMessage());
        $failures[] = ['id' => $message['id'], 'error' => $e->getMessage()];
    }
}
return ['processed' => count($messages) - count($failures), 'failures' => $failures];
```

## Never Expose Exception Messages to Users

**Never put `$e->getMessage()` in flash messages, JSON responses, or any output visible to end users.** Exception messages can contain SQL queries, file paths, API credentials, or other internal details.

Instead, generate a traceable error ID, log the full error with context, and show the user a generic message with the ID:

```php
// Bad — leaks internal details to user
$this->session->setFlash('error', "Failed: " . $e->getMessage());

// Good — traceable error ID, structured context in logs
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;

$errorId = uniqid('err-');
$this->logger->error('Failed to send message', [
    'errorId' => $errorId,
    'phone' => $phoneNumber,
    'exception' => ExceptionContext::fromThrowable($e),
]);
$this->session->setFlash('error', "An error occurred (ref: {$errorId}). Contact support if this persists.");
```

**Where `$e->getMessage()` IS appropriate:**
- Exception wrapping (`throw new ApiException("...: " . $e->getMessage(), 0, $e)`) — internal plumbing between layers
- CLI command output (`$io->error(...)`) — operators running commands directly

**Where it is NOT appropriate:**
- Flash messages (`$this->session->setFlash(...)`)
- JSON responses sent to the browser (`new JsonResponse(['message' => ...])`)
- Any HTML rendered in templates
- Log message strings (use context array instead — see below)

## PSR-3 Logging Context

**Always use PSR-3 context arrays instead of string interpolation or concatenation in log calls.**

OpenEMR's `SystemLogger` extends Monolog, which supports PSR-3 message placeholders (`{braces}`) and context arrays. Structured context enables log aggregators (CloudWatch, Datadog) to index and search on individual fields.

```php
// Bad — string concatenation
$this->logger->error("Failed to poll conversation {$conversationId}: " . $e->getMessage());
$this->logger->info("Sent keyword auto-response to: {$phoneNumber}");

// Good — PSR-3 context
$this->logger->error('Failed to poll conversation', [
    'conversationId' => $conversationId,
    'exception' => ExceptionContext::fromThrowable($e),
]);
$this->logger->info('Sent keyword auto-response', ['phone' => $phoneNumber]);
```

**Rules:**
- Log message is a static string — no variables interpolated into it
- All variable data goes in the context array
- Pass exceptions as `'exception' => ExceptionContext::fromThrowable($e)` — see [Logging Throwables](#logging-throwables) below
- Use descriptive keys (`'patientId'`, `'phone'`, `'conversationId'`), not generic ones (`'id'`, `'value'`)

## Logging Throwables

OpenEMR's `SystemLogger` `json_encode`s context object values. `\Throwable` exposes no public properties, so passing `'exception' => $e` directly produces `"exception":"{}"` in the log — useless for debugging.

Always route exceptions through `ExceptionContext::fromThrowable()`:

```php
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;

$this->logger->error('Failed to do thing', [
    'exception' => ExceptionContext::fromThrowable($e),
]);
```

The helper produces `class`, `message`, `file:line`, `trace`, and recurses into `getPrevious()`. The previous-exception chain matters: services often re-throw a domain exception wrapping the underlying cause (e.g. `ValidationException` wrapping a Sinch `ApiException`). Without recursing, the log loses the real reason.

## API Exception Handling

When calling Sinch APIs, wrap in try-catch and convert to appropriate exceptions:

```php
try {
    $response = $this->client->sendMessage($payload);
} catch (GuzzleException $e) {
    $this->logger->error('Sinch API call failed', ['exception' => ExceptionContext::fromThrowable($e)]);
    throw new ApiException("Failed to send message: " . $e->getMessage(), 0, $e);
}
```
