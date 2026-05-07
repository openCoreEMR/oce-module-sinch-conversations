<?php

/**
 * Sends an opt-in welcome SMS when the chart hipaa_allowsms field flips to YES
 *
 * Under the chart-as-source-of-truth model, hipaa_allowsms is itself the
 * consent record — the module does not mirror it into oce_sinch_patient_consent.
 * This listener exists only to send the patient a welcome message on the
 * transition, so they know they will start receiving SMS from the clinic.
 *
 * Coverage note: this listener subscribes to PatientUpdatedEvent /
 * PatientCreatedEvent. Several legacy chart save paths
 * (interface/patient_file/summary/demographics_save.php and
 * interface/new/new_patient_save.php) dispatch PatientUpdatedEventAux or
 * no event at all, so a NO->YES toggle through those UI paths will not
 * fire the welcome SMS. The patient is still eligible for reminders the
 * moment the chart shows YES — MessageService::assertPatientEligible()
 * reads hipaa_allowsms live at send time, independent of any event. The
 * welcome SMS is therefore best-effort; reliable coverage is tracked
 * separately. Eligibility coverage is not affected.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Listener;

use OpenCoreEMR\Modules\SinchConversations\ConsentBlock;
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\SkipReason;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Patient\PatientCreatedEvent;
use OpenEMR\Events\Patient\PatientUpdatedEvent;

class PatientConsentListener
{
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
        $this->sendWelcomeIfNotBlocked($pid, $phone);
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

        // Only react to NO->YES. YES->NO needs no module-side action under
        // chart-as-source-of-truth: the chart NO already gates future sends.
        if (!$newYes || $oldYes) {
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

        $this->sendWelcomeIfNotBlocked($pid, $phone);
    }

    /**
     * Send the welcome SMS unless a module-side block applies
     *
     * A staff chart toggle should not silently override either a patient's
     * channel-native opt-out (e.g., a prior STOP) or a known carrier block.
     * sendOptInConfirmation() bypasses the normal eligibility gate
     * (skipConsentCheck=true), so this listener is the only thing standing
     * between a chart toggle and a send to a number we already know is
     * blocked. Reuses ConsentBlock::evaluate so the block ordering and
     * context shape match the send gate, the appointment-reminder cron,
     * and the diagnostic verdict surface.
     */
    private function sendWelcomeIfNotBlocked(int $pid, string $phone): void
    {
        $block = ConsentBlock::evaluate($this->consentService->getConsent($pid, $phone));
        if ($block !== null) {
            $message = match ($block->reason) {
                SkipReason::CarrierBlocked => 'Skipped welcome SMS: carrier block present',
                SkipReason::ModuleOptOut => 'Skipped welcome SMS: module opt-out present',
                default => 'Skipped welcome SMS: ' . $block->reason->value,
            };
            $this->logger->info($message, ['patientId' => $pid] + $block->context);
            return;
        }

        try {
            $this->consentService->sendOptInConfirmation($pid, $phone);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send opt-in confirmation', [
                'patientId' => $pid,
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
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
