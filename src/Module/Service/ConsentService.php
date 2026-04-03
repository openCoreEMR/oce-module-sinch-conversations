<?php

/**
 * Patient Consent Management Service
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\Channel;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class ConsentService
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly TemplateService $templateService,
        private readonly MessageService $messageService
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Check if patient has active consent
     *
     * Normalizes the phone number to E.164 before lookup so that consent
     * recorded as +15551234567 is found regardless of input format.
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @return bool
     */
    public function hasConsent(int $patientId, string $phoneNumber): bool
    {
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot check consent with unparseable phone number', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
            ]);
            return false;
        }

        $sql = "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId, $normalized]);

        if (!$result) {
            return false;
        }

        return ($result['opted_in'] ?? false) && !($result['opted_out'] ?? false);
    }

    /**
     * Record opt-in and send confirmation message
     *
     * Only syncs hipaa_allowsms when the channel is SMS, since that flag
     * represents SMS consent specifically, not general messaging consent.
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @param string $method web_form, portal, in_person, etc
     * @param ?string $ipAddress
     * @param ?Channel $channel The messaging channel, or null for unrecognized channels
     * @return bool True if opt-in confirmation was sent, false if it failed.
     *               Consent is always recorded regardless of return value.
     */
    public function optIn(
        int $patientId,
        string $phoneNumber,
        string $method,
        ?string $ipAddress = null,
        ?Channel $channel = Channel::SMS
    ): bool {
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot opt in with unparseable phone number', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
            ]);
            return false;
        }
        $phoneNumber = $normalized;

        $sql = "INSERT INTO oce_sinch_patient_consent (
            patient_id, phone_number, opted_in, opt_in_method,
            opt_in_date, opt_in_ip_address, opted_out,
            created_at, updated_at
        ) VALUES (?, ?, TRUE, ?, NOW(), ?, FALSE, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            opted_in = TRUE,
            opt_in_method = VALUES(opt_in_method),
            opt_in_date = NOW(),
            opt_in_ip_address = VALUES(opt_in_ip_address),
            opted_out = FALSE,
            updated_at = NOW()";

        QueryUtils::sqlStatementThrowException($sql, [
            $patientId,
            $phoneNumber,
            $method,
            $ipAddress,
        ]);

        if ($channel === Channel::SMS) {
            $this->syncHipaaAllowSms($patientId, 'YES');
        }
        $this->logger->debug('Patient opted in', [
            'patientId' => $patientId,
            'method' => $method,
            'channel' => $channel?->value,
        ]);

        try {
            $this->sendOptInConfirmation($patientId, $phoneNumber);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send opt-in confirmation', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
                'exception' => $e,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Record opt-out (STOP keyword or channel-native opt-out)
     *
     * Only syncs hipaa_allowsms when the channel is SMS, since that flag
     * represents SMS consent specifically. Opting out of a non-SMS channel
     * (e.g., Viber, WhatsApp) must not disable SMS consent.
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @param string $method sms_stop, web_form, in_person, sinch_WHATSAPP, etc
     * @param ?Channel $channel The messaging channel, or null for unrecognized channels
     */
    public function optOut(
        int $patientId,
        string $phoneNumber,
        string $method,
        ?Channel $channel = Channel::SMS
    ): void {
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot opt out with unparseable phone number', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
            ]);
            return;
        }
        $phoneNumber = $normalized;

        $sql = "UPDATE oce_sinch_patient_consent
                SET opted_out = TRUE,
                    opt_out_method = ?,
                    opt_out_date = NOW(),
                    updated_at = NOW()
                WHERE patient_id = ? AND phone_number = ?";

        QueryUtils::sqlStatementThrowException($sql, [$method, $patientId, $phoneNumber]);

        if ($channel === Channel::SMS) {
            $this->syncHipaaAllowSms($patientId, 'NO');
        }
        $this->logger->debug('Patient opted out', [
            'patientId' => $patientId,
            'method' => $method,
            'channel' => $channel?->value,
        ]);
    }

    /**
     * Get consent record for patient
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @return array<string, mixed>|null
     */
    public function getConsent(int $patientId, string $phoneNumber): ?array
    {
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot get consent with unparseable phone number', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
            ]);
            return null;
        }

        $sql = "SELECT * FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId, $normalized]);

        return $result ?: null;
    }

    /**
     * Record a carrier-level block on a patient's phone number
     *
     * Set when a delivery failure contains an SMPP error code indicating the
     * carrier has blocked the number (255, 61, 151), or when the Sinch consent
     * API reports an opt-out we did not see via webhook.
     */
    public function setCarrierBlock(int $patientId, string $phoneNumber, string $reason): void
    {
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot set carrier block with unparseable phone number', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
            ]);
            return;
        }

        // Use INSERT ... ON DUPLICATE KEY UPDATE so the block is recorded even
        // when no consent record exists yet (e.g. the patient received a message
        // through an external path and was never formally opted in).
        $sql = "INSERT INTO oce_sinch_patient_consent (
                    patient_id, phone_number, opted_in, opted_out,
                    carrier_blocked, carrier_blocked_at, carrier_block_reason,
                    created_at, updated_at
                ) VALUES (?, ?, FALSE, FALSE, TRUE, NOW(), ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    carrier_blocked = TRUE,
                    carrier_blocked_at = NOW(),
                    carrier_block_reason = VALUES(carrier_block_reason),
                    updated_at = NOW()";

        QueryUtils::sqlStatementThrowException($sql, [$patientId, $normalized, $reason]);

        $this->logger->info('Carrier block set', [
            'patientId' => $patientId,
            'phone' => $normalized,
            'reason' => $reason,
        ]);
    }

    /**
     * Clear a carrier block after a successful delivery confirms the block is lifted
     *
     * Intentionally does NOT re-opt-in the patient. The carrier block flow
     * calls optOut(), so the patient remains opted out until staff manually
     * re-opts them in. This matches the issue spec: "When staff re-opts-in
     * a carrier-blocked patient" is an explicit human action.
     */
    public function clearCarrierBlock(int $patientId, string $phoneNumber): void
    {
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot clear carrier block with unparseable phone number', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
            ]);
            return;
        }

        $sql = "UPDATE oce_sinch_patient_consent
                SET carrier_blocked = FALSE,
                    carrier_blocked_at = NULL,
                    carrier_block_reason = NULL,
                    updated_at = NOW()
                WHERE patient_id = ? AND phone_number = ?";

        QueryUtils::sqlStatementThrowException($sql, [$patientId, $normalized]);

        $this->logger->info('Carrier block cleared', [
            'patientId' => $patientId,
            'phone' => $normalized,
        ]);
    }

    /**
     * Check if a patient's phone number is carrier-blocked
     *
     * @return array{carrier_blocked_at: ?string, carrier_block_reason: ?string}|null
     */
    public function getCarrierBlock(int $patientId, string $phoneNumber): ?array
    {
        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Cannot check carrier block with unparseable phone number', [
                'patientId' => $patientId,
                'phone' => $phoneNumber,
            ]);
            return null;
        }

        $sql = "SELECT carrier_blocked_at, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ? AND carrier_blocked = TRUE";
        $result = QueryUtils::querySingleRow($sql, [$patientId, $normalized]);

        if (!$result) {
            return null;
        }

        $blockedAt = $result['carrier_blocked_at'] ?? null;
        $blockReason = $result['carrier_block_reason'] ?? null;

        return [
            'carrier_blocked_at' => is_string($blockedAt) ? $blockedAt : null,
            'carrier_block_reason' => is_string($blockReason) ? $blockReason : null,
        ];
    }

    /**
     * Sync hipaa_allowsms on the patient chart to keep a single source of truth
     */
    private function syncHipaaAllowSms(int $patientId, string $value): void
    {
        $sql = "UPDATE patient_data SET hipaa_allowsms = ? WHERE pid = ?";
        QueryUtils::sqlStatementThrowException($sql, [$value, $patientId]);
    }

    /**
     * Send initial opt-in confirmation message
     *
     * @param int $patientId
     * @param string $phoneNumber
     */
    private function sendOptInConfirmation(int $patientId, string $phoneNumber): void
    {
        $variables = [
            'clinic_name' => $this->config->getClinicName(),
            'opt_out' => 'Reply STOP to opt-out',
        ];

        $message = $this->templateService->render('opt_in_confirmation', $variables);

        $this->messageService->sendToPatient($patientId, $phoneNumber, $message, new MessageOptions(
            templateKey: 'opt_in_confirmation',
            skipConsentCheck: true,
        ));
    }
}
