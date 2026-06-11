<?php

/**
 * Consent Sync Service - reconcile Sinch consent API with local records
 *
 * Polls the Sinch Consent Management API for opted-out identities and
 * reconciles with local consent records. This catches opt-outs that
 * bypassed webhooks entirely (e.g. carrier-intercepted STOP messages).
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\ErrorId;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class ConsentSyncService
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ConversationApiClient $apiClient,
        private readonly ConsentService $consentService
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Sync opt-outs from Sinch consent API with local consent records
     *
     * Two-way reconciliation:
     * 1. Block patients who appear in the Sinch opt-out list but aren't locally blocked
     * 2. Clear blocks for patients who are locally blocked but no longer appear in Sinch
     *
     * @return array{blocked: int, cleared: int, already_blocked: int, errors: int}
     */
    public function syncOptOuts(): array
    {
        $appId = $this->config->getSinchAppId();
        $this->logger->info('Starting consent sync from Sinch API', ['appId' => $appId]);

        $optOuts = $this->apiClient->listOptOuts($appId);

        // Collect all opted-out phone numbers from Sinch for the reverse check
        $sinchOptedOutPhones = [];

        $blocked = 0;
        $alreadyBlocked = 0;
        $errors = 0;

        foreach ($optOuts as $entry) {
            $identity = (string) ($entry['identity'] ?? '');
            if ($identity === '') {
                continue;
            }

            // Sinch stores identities without the + prefix
            $phoneNumber = '+' . ltrim($identity, '+');
            $normalized = PhoneNormalizer::toE164($phoneNumber);
            if ($normalized === null) {
                $this->logger->warning('Skipping unparseable identity from consent API', [
                    'identity' => $identity,
                ]);
                continue;
            }

            $sinchOptedOutPhones[$normalized] = true;

            $patients = $this->findPatientsByPhone($normalized);
            if ($patients === []) {
                continue;
            }

            foreach ($patients as $patientId) {
                try {
                    $block = $this->consentService->getCarrierBlock($patientId, $normalized);
                    if ($block !== null) {
                        $alreadyBlocked++;
                        continue;
                    }

                    $this->consentService->setCarrierBlock($patientId, $normalized, 'consent_api_sync');
                    // The consent API opt-out list is cross-channel; skip hipaa_allowsms
                    // sync since we don't know which channel triggered the opt-out.
                    $this->consentService->optOut($patientId, $normalized, 'consent_api_sync', channel: null);
                    $blocked++;
                } catch (\Throwable $e) {
                    $errors++;
                    $errorId = ErrorId::generate();
                    $this->logger->error('Failed to sync consent for patient', [
                        'patientId' => $patientId,
                        'phone' => $normalized,
                        'errorId' => $errorId,
                        'exception' => ExceptionContext::fromThrowable($e),
                    ]);
                }
            }
        }

        // Clear local carrier blocks that no longer appear in the Sinch opt-out list.
        // Only clear blocks that were set by a previous consent_api_sync, not blocks
        // from SMPP delivery failures (those are cleared by successful delivery).
        $cleared = $this->clearLiftedBlocks($sinchOptedOutPhones);

        $this->logger->info('Consent sync completed', [
            'blocked' => $blocked,
            'cleared' => $cleared,
            'already_blocked' => $alreadyBlocked,
            'errors' => $errors,
        ]);

        return [
            'blocked' => $blocked,
            'cleared' => $cleared,
            'already_blocked' => $alreadyBlocked,
            'errors' => $errors,
        ];
    }

    /**
     * Check a single phone number against the Sinch consent API
     *
     * @return array<string, mixed> Consent status data, or empty array if not opted out
     */
    public function checkIdentity(string $phoneNumber): array
    {
        $appId = $this->config->getSinchAppId();
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot check consent for unparseable phone', [
                'phone' => $phoneNumber,
            ]);
            return [];
        }

        return $this->apiClient->getConsentStatus($appId, $normalized);
    }

    /**
     * Clear carrier blocks that were set by consent_api_sync but no longer
     * appear in the Sinch opt-out list (block was lifted upstream)
     *
     * @param array<string, true> $sinchOptedOutPhones Normalized phones still opted out in Sinch
     */
    private function clearLiftedBlocks(array $sinchOptedOutPhones): int
    {
        $sql = "SELECT patient_id, phone_number
                FROM oce_sinch_patient_consent
                WHERE carrier_blocked = TRUE AND carrier_block_reason = 'consent_api_sync'";
        $localBlocks = QueryUtils::fetchRecords($sql, []);

        $cleared = 0;
        foreach ($localBlocks as $row) {
            $phone = (string) $row['phone_number'];
            if (isset($sinchOptedOutPhones[$phone])) {
                continue;
            }

            $patientId = (int) $row['patient_id'];
            try {
                $this->consentService->clearCarrierBlock($patientId, $phone);
                $cleared++;
                $this->logger->info('Cleared carrier block no longer in Sinch opt-out list', [
                    'patientId' => $patientId,
                    'phone' => $phone,
                ]);
            } catch (\Throwable $e) {
                $errorId = ErrorId::generate();
                $this->logger->error('Failed to clear lifted carrier block', [
                    'patientId' => $patientId,
                    'phone' => $phone,
                    'errorId' => $errorId,
                    'exception' => ExceptionContext::fromThrowable($e),
                ]);
            }
        }

        return $cleared;
    }

    /**
     * Find all patient IDs associated with a phone number
     *
     * @return list<int>
     */
    private function findPatientsByPhone(string $phoneNumber): array
    {
        $sql = "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?";
        $results = QueryUtils::fetchRecords($sql, [$phoneNumber]);

        return array_map(
            static fn(array $row): int => (int) $row['patient_id'],
            $results
        );
    }
}
