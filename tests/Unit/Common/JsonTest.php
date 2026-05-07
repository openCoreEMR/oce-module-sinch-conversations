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

use JsonException;
use OpenCoreEMR\Modules\SinchConversations\Common\Json;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
    public function testEncodeReturnsString(): void
    {
        $this->assertSame('{"a":1}', Json::encode(['a' => 1]));
    }

    public function testEncodeAcceptsExtraFlags(): void
    {
        // Default behaviour escapes forward slashes; passing
        // JSON_UNESCAPED_SLASHES through the wrapper must suppress that.
        $this->assertSame('"a\/b"', Json::encode('a/b'));
        $this->assertSame('"a/b"', Json::encode('a/b', JSON_UNESCAPED_SLASHES));
    }

    public function testEncodeThrowsOnUnencodableValue(): void
    {
        $this->expectException(JsonException::class);
        Json::encode("\xB1\x31");
    }

    public function testDecodeReturnsAssociativeArray(): void
    {
        $this->assertSame(['a' => 1, 'b' => [2, 3]], Json::decode('{"a":1,"b":[2,3]}'));
    }

    public function testDecodeThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);
        Json::decode('{not json');
    }
}
