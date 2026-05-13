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
     * GlobalConfig is private on Bootstrap. Read it via a closure rebound
     * to Bootstrap's scope — works on every supported PHP version and
     * keeps the production class API untouched. Reflection's
     * setAccessible() became a no-op in PHP 8.1, but a bound closure is
     * the more portable, intent-revealing approach.
     */
    private function getGlobalConfigForTesting(): \OpenCoreEMR\Modules\SinchConversations\GlobalConfig
    {
        $reader = \Closure::bind(
            fn(): \OpenCoreEMR\Modules\SinchConversations\GlobalConfig => $this->globalsConfig,
            $this,
            Bootstrap::class
        );
        return $reader();
    }
}
