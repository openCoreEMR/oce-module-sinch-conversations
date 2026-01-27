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

## API Exception Handling

When calling Sinch APIs, wrap in try-catch and convert to appropriate exceptions:

```php
try {
    $response = $this->client->sendMessage($payload);
} catch (GuzzleException $e) {
    $this->logger->error("API call failed: " . $e->getMessage());
    throw new ApiException("Failed to send message: " . $e->getMessage(), 0, $e);
}
```
