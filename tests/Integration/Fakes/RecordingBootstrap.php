<?php

/**
 * Bootstrap subclass that returns a RecordingMessageService.
 *
 * Production Bootstrap::getMessageService() is a public method called once
 * by getAppointmentReminderService(). Overriding it here is the entire
 * test seam — no production-side change required.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fakes;

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;

class RecordingBootstrap extends Bootstrap
{
    private ?RecordingMessageService $recorder = null;

    public function getMessageService(): MessageService
    {
        return $this->recorder ??= new RecordingMessageService(
            $this->getGlobalConfigForTesting(),
            $this->getConversationApiClient()
        );
    }

    public function getRecorder(): RecordingMessageService
    {
        // Trigger lazy construction so callers can read sends after run().
        $this->getMessageService();
        \assert($this->recorder !== null);
        return $this->recorder;
    }

    /**
     * GlobalConfig is private on Bootstrap. We need a handle to construct
     * the RecordingMessageService with the same config the real one uses.
     * Reflection here keeps the production class API untouched.
     */
    private function getGlobalConfigForTesting(): \OpenCoreEMR\Modules\SinchConversations\GlobalConfig
    {
        $ref = new \ReflectionClass(Bootstrap::class);
        $prop = $ref->getProperty('globalsConfig');
        return $prop->getValue($this);
    }
}
