<?php

/**
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Common;

use OpenCoreEMR\Modules\SinchConversations\Common\ArrayPath;
use PHPUnit\Framework\TestCase;

class ArrayPathTest extends TestCase
{
    public function testStringAtReadsLeaf(): void
    {
        $data = ['error' => ['message' => 'oops']];
        $this->assertSame('oops', ArrayPath::stringAt($data, 'error', 'message'));
    }

    public function testStringAtReturnsNullWhenLeafIsNotString(): void
    {
        $this->assertNull(ArrayPath::stringAt(['error' => ['message' => 42]], 'error', 'message'));
        $this->assertNull(ArrayPath::stringAt(['error' => ['message' => null]], 'error', 'message'));
    }

    public function testStringAtReturnsNullForMissingKey(): void
    {
        $this->assertNull(ArrayPath::stringAt(['error' => []], 'error', 'message'));
        $this->assertNull(ArrayPath::stringAt([], 'error', 'message'));
    }

    public function testStringAtReturnsNullWhenIntermediateIsNotArray(): void
    {
        // The motivating bug: $data['error'] is a scalar, so $data['error']['message']
        // would warn under naive `??` chains. ArrayPath must return null silently.
        $this->assertNull(ArrayPath::stringAt(['error' => 'not-an-object'], 'error', 'message'));
        $this->assertNull(ArrayPath::stringAt(['error' => null], 'error', 'message'));
    }

    public function testStringAtReturnsNullForNonArrayRoot(): void
    {
        $this->assertNull(ArrayPath::stringAt(null, 'error'));
        $this->assertNull(ArrayPath::stringAt('a string', 'error'));
        $this->assertNull(ArrayPath::stringAt(42, 'error'));
    }

    public function testStringAtAcceptsEmptyString(): void
    {
        $this->assertSame('', ArrayPath::stringAt(['k' => ''], 'k'));
    }

    public function testFirstNonEmptyStringReturnsFirstHit(): void
    {
        $data = ['error_description' => 'desc', 'error' => 'fallback'];
        $this->assertSame('desc', ArrayPath::firstNonEmptyString($data, 'error_description', 'error'));
    }

    public function testFirstNonEmptyStringFallsThroughEmptyAndMissing(): void
    {
        $data = ['error_description' => '', 'error' => 'fallback'];
        $this->assertSame('fallback', ArrayPath::firstNonEmptyString($data, 'error_description', 'error'));
    }

    public function testFirstNonEmptyStringReturnsNullWhenNothingMatches(): void
    {
        $this->assertNull(ArrayPath::firstNonEmptyString(['error' => ''], 'error_description', 'error'));
        $this->assertNull(ArrayPath::firstNonEmptyString(null, 'a', 'b'));
    }
}
