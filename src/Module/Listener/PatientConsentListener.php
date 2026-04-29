<?php

/**
 * Translates patient_data hipaa_allowsms transitions into ConsentService calls
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Listener;

use OpenCoreEMR\Modules\SinchConversations\Channel;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Patient\PatientCreatedEvent;
use OpenEMR\Events\Patient\PatientUpdatedEvent;

class PatientConsentListener
{
    public const CONSENT_METHOD = 'chart_hipaa_allowsms';

    private readonly SystemLogger $logger;

    public function __construct(
        private readonly ConsentService $consentService,
    ) {
        $this->logger = new SystemLogger();
    }

    public function onPatientCreated(PatientCreatedEvent $event): void
    {
        $data = $event->getPatientData();
        if (!$this->isAllowSmsYes($data)) {
            return;
        }
        $pid = $this->extractPid($data);
        $phone = $this->extractPhone($data);
        if ($pid === null || $phone === null) {
            $this->logSkipped('created', $pid, $phone);
            return;
        }
        $this->consentService->optIn($pid, $phone, self::CONSENT_METHOD, null, Channel::SMS);
    }

    public function onPatientUpdated(PatientUpdatedEvent $event): void
    {
        $newData = $event->getNewPatientData();
        // The new-data payload only includes fields that were updated. If the
        // chart save did not touch hipaa_allowsms, there is no transition to react to.
        if (!is_array($newData) || !array_key_exists('hipaa_allowsms', $newData)) {
            return;
        }

        $oldData = $event->getDataBeforeUpdate();
        $oldYes = is_array($oldData) && $this->isAllowSmsYes($oldData);
        $newYes = $this->isAllowSmsYes($newData);
        if ($oldYes === $newYes) {
            return;
        }

        $pid = $this->extractPid($newData);
        if ($pid === null && is_array($oldData)) {
            $pid = $this->extractPid($oldData);
        }
        // The phone may not be in the partial update payload; fall back to the
        // pre-update row so a NO->YES toggle that doesn't re-post phone_cell still works.
        $phone = $this->extractPhone($newData);
        if ($phone === null && is_array($oldData)) {
            $phone = $this->extractPhone($oldData);
        }
        if ($pid === null || $phone === null) {
            $this->logSkipped('updated', $pid, $phone);
            return;
        }

        if ($newYes) {
            $this->consentService->optIn($pid, $phone, self::CONSENT_METHOD, null, Channel::SMS);
        } else {
            $this->consentService->optOut($pid, $phone, self::CONSENT_METHOD, Channel::SMS);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function isAllowSmsYes(array $data): bool
    {
        $value = $data['hipaa_allowsms'] ?? null;
        return is_string($value) && strcasecmp($value, 'YES') === 0;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function extractPid(array $data): ?int
    {
        $pid = $data['pid'] ?? null;
        if (is_int($pid) && $pid > 0) {
            return $pid;
        }
        if (is_string($pid) && preg_match('/^\d+$/', $pid) === 1 && (int) $pid > 0) {
            return (int) $pid;
        }
        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function extractPhone(array $data): ?string
    {
        $phone = $data['phone_cell'] ?? null;
        if (is_string($phone) && trim($phone) !== '') {
            return $phone;
        }
        return null;
    }

    private function logSkipped(string $action, ?int $pid, ?string $phone): void
    {
        $this->logger->info('Skipped chart-driven consent action: missing pid or phone_cell', [
            'action' => $action,
            'patientId' => $pid,
            'phonePresent' => $phone !== null,
        ]);
    }
}
