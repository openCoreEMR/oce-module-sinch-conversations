<?php

/**
 * Mock SystemLogger for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Logging;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Mock SystemLogger to avoid dependencies on OpenEMR core during tests
 */
class SystemLogger implements LoggerInterface
{
    /**
     * @var array<int, array{level: string, message: string|Stringable, context: array<mixed>}>
     */
    private static array $logs = [];

    public function __construct()
    {
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'emergency', 'message' => $message, 'context' => $context];
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'alert', 'message' => $message, 'context' => $context];
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        self::$logs[] = ['level' => (string) $level, 'message' => $message, 'context' => $context];
    }

    /**
     * @return array<int, array{level: string, message: string|Stringable, context: array<mixed>}>
     */
    public static function getLogs(): array
    {
        return self::$logs;
    }

    public static function clearLogs(): void
    {
        self::$logs = [];
    }
}
