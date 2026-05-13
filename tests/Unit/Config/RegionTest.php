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

use OpenCoreEMR\Sinch\Conversation\Config\Region;
use PHPUnit\Framework\TestCase;

class RegionTest extends TestCase
{
    public function testUsConversationApiBaseUrl(): void
    {
        $this->assertSame('https://us.conversation.api.sinch.com', Region::Us->conversationApiBaseUrl());
    }

    public function testEuConversationApiBaseUrl(): void
    {
        $this->assertSame('https://eu.conversation.api.sinch.com', Region::Eu->conversationApiBaseUrl());
    }

    public function testUsAuthBaseUrl(): void
    {
        $this->assertSame('https://us.auth.sinch.com', Region::Us->authBaseUrl());
    }

    public function testEuAuthBaseUrl(): void
    {
        $this->assertSame('https://eu.auth.sinch.com', Region::Eu->authBaseUrl());
    }

    public function testUsTemplateApiBaseUrl(): void
    {
        $this->assertSame('https://us.template.api.sinch.com', Region::Us->templateApiBaseUrl());
    }

    public function testEuTemplateApiBaseUrl(): void
    {
        $this->assertSame('https://eu.template.api.sinch.com', Region::Eu->templateApiBaseUrl());
    }

    public function testCases(): void
    {
        // Ensure both supported regions are present and no extras have crept in
        // — exhaustive `match` on Region in production code depends on this.
        $this->assertSame([Region::Us, Region::Eu], Region::cases());
    }
}
