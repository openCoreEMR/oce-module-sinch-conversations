<?php

/**
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Config;

use OpenCoreEMR\Sinch\Conversation\Config\StandaloneConfig;
use PHPUnit\Framework\TestCase;

class StandaloneConfigTest extends TestCase
{
    public function testGetSinchApiBaseUrlDefaultsToUs(): void
    {
        $config = new StandaloneConfig([]);

        $this->assertSame('https://us.conversation.api.sinch.com', $config->getSinchApiBaseUrl());
    }

    public function testGetSinchApiBaseUrlReturnsEuHostForEuRegion(): void
    {
        $config = new StandaloneConfig(['region' => 'eu']);

        $this->assertSame('https://eu.conversation.api.sinch.com', $config->getSinchApiBaseUrl());
    }

    public function testGetSinchApiBaseUrlFallsBackToUsForUnknownRegion(): void
    {
        $config = new StandaloneConfig(['region' => 'asia']);

        $this->assertSame('https://us.conversation.api.sinch.com', $config->getSinchApiBaseUrl());
    }
}
