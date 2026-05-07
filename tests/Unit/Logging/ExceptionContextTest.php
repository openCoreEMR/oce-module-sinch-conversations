<?php

/**
 * Unit tests for ExceptionContext
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Logging;

use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExceptionContextTest extends TestCase
{
    public function testFromThrowableProducesExpectedShape(): void
    {
        $line = __LINE__ + 1;
        $e = new RuntimeException('boom');

        $context = ExceptionContext::fromThrowable($e);

        $this->assertSame(RuntimeException::class, $context['class']);
        $this->assertSame('boom', $context['message']);
        $this->assertSame(__FILE__ . ':' . $line, $context['file']);
        $this->assertNotEmpty($context['trace']);
        $this->assertArrayNotHasKey('previous', $context, 'no previous chain when none was set');
    }

    public function testFromThrowableRecursesPreviousChain(): void
    {
        $root = new RuntimeException('root cause');
        $middle = new RuntimeException('middle wrap', 0, $root);
        $top = new RuntimeException('outer', 0, $middle);

        $context = ExceptionContext::fromThrowable($top);

        $this->assertSame('outer', $context['message']);
        $this->assertArrayHasKey('previous', $context);
        $this->assertSame('middle wrap', $context['previous']['message']);
        $this->assertArrayHasKey('previous', $context['previous']);
        $this->assertSame('root cause', $context['previous']['previous']['message']);
        $this->assertArrayNotHasKey('previous', $context['previous']['previous']);
    }

    public function testFromThrowableProducesJsonEncodableArray(): void
    {
        $context = ExceptionContext::fromThrowable(new RuntimeException('encode me', 0, new RuntimeException('inner')));

        $json = json_encode($context);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertSame('encode me', $decoded['message']);
        $this->assertSame('inner', $decoded['previous']['message']);
    }
}
