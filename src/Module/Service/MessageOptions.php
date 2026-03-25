<?php

/**
 * Typed options for sending messages
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\Channel;

class MessageOptions
{
    /**
     * @param ?string $sender Override the clinic phone as sender
     * @param ?Channel $channel Channel for sender routing (set automatically when sender is auto-populated)
     * @param ?string $templateKey Stored in oce_sinch_messages for tracking which template was used
     * @param array<string, mixed> $metadata Opaque data passed through to the Sinch API
     * @param list<Channel> $channelPriority Channel priority order for message routing
     * @param bool $skipConsentCheck Bypass eligibility checks for system messages (e.g. opt-in confirmations)
     */
    public function __construct(
        public readonly ?string $sender = null,
        public readonly ?Channel $channel = null,
        public readonly ?string $templateKey = null,
        public readonly array $metadata = [],
        public readonly array $channelPriority = [],
        public readonly bool $skipConsentCheck = false,
    ) {
    }

    /**
     * Build the options array expected by ConversationApiClient::sendMessage()
     *
     * @return array<string, mixed>
     */
    public function toApiOptions(): array
    {
        $options = [];

        if ($this->sender !== null) {
            $options['sender'] = $this->sender;
        }

        if ($this->channel !== null) {
            $options['channel'] = $this->channel->value;
        }

        if ($this->channelPriority !== []) {
            $options['channel_priority'] = array_map(
                static fn(Channel $c): string => $c->value,
                $this->channelPriority
            );
        }

        if ($this->metadata !== []) {
            $options['metadata'] = $this->metadata;
        }

        return $options;
    }
}
