<?php

/**
 * Unit tests for MessageOptions
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\Channel;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use PHPUnit\Framework\TestCase;

class MessageOptionsTest extends TestCase
{
    public function testDefaultsProduceEmptyApiOptions(): void
    {
        $options = new MessageOptions();

        $this->assertSame([], $options->toApiOptions());
        $this->assertNull($options->sender);
        $this->assertNull($options->channel);
        $this->assertNull($options->templateKey);
        $this->assertSame([], $options->metadata);
        $this->assertSame([], $options->channelPriority);
        $this->assertFalse($options->skipConsentCheck);
    }

    public function testToApiOptionsIncludesSenderAndChannel(): void
    {
        $options = new MessageOptions(
            sender: '+15551234567',
            channel: Channel::SMS,
        );

        $this->assertSame([
            'sender' => '+15551234567',
            'channel' => 'SMS',
        ], $options->toApiOptions());
    }

    public function testToApiOptionsIncludesChannelPriority(): void
    {
        $options = new MessageOptions(
            channelPriority: [Channel::SMS, Channel::WHATSAPP],
        );

        $this->assertSame([
            'channel_priority' => ['SMS', 'WHATSAPP'],
        ], $options->toApiOptions());
    }

    public function testToApiOptionsIncludesMetadata(): void
    {
        $options = new MessageOptions(
            metadata: ['campaign' => 'reminder'],
        );

        $this->assertSame([
            'metadata' => ['campaign' => 'reminder'],
        ], $options->toApiOptions());
    }

    public function testToApiOptionsOmitsModuleOnlyFields(): void
    {
        $options = new MessageOptions(
            templateKey: 'appointment_reminder',
            skipConsentCheck: true,
        );

        $this->assertSame([], $options->toApiOptions());
        $this->assertSame('appointment_reminder', $options->templateKey);
        $this->assertTrue($options->skipConsentCheck);
    }

    public function testToApiOptionsFullyPopulated(): void
    {
        $options = new MessageOptions(
            sender: '+15551234567',
            channel: Channel::WHATSAPP,
            templateKey: 'test',
            metadata: ['key' => 'value'],
            channelPriority: [Channel::WHATSAPP, Channel::SMS],
            skipConsentCheck: true,
        );

        $this->assertSame([
            'sender' => '+15551234567',
            'channel' => 'WHATSAPP',
            'channel_priority' => ['WHATSAPP', 'SMS'],
            'metadata' => ['key' => 'value'],
        ], $options->toApiOptions());
    }
}
