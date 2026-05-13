<?php

/**
 * Test fake that captures sendToPatient() calls instead of dispatching them.
 *
 * Substituted into Bootstrap by RecordingBootstrap so the real
 * AppointmentReminderService runs through its actual code paths but never
 * touches Sinch or writes to oce_sinch_messages. Each captured send is a
 * tuple of (patientId, phone, message, options).
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fakes;

use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;

class RecordingMessageService extends MessageService
{
    /**
     * @var list<array{patientId: int, phone: string, message: string, options: ?MessageOptions}>
     */
    private array $sends = [];

    /**
     * @return array<string, mixed>
     */
    public function sendToPatient(
        int $patientId,
        string $phoneNumber,
        string $message,
        ?MessageOptions $options = null
    ): array {
        $this->sends[] = [
            'patientId' => $patientId,
            'phone' => $phoneNumber,
            'message' => $message,
            'options' => $options,
        ];

        return ['id' => 'fake_msg_' . count($this->sends)];
    }

    /**
     * @return list<array{patientId: int, phone: string, message: string, options: ?MessageOptions}>
     */
    public function getSends(): array
    {
        return $this->sends;
    }
}
