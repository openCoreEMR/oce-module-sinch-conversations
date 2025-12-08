<?php

/**
 * Patient Consent Management Service
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Service;

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
     * @param int $patientId
     * @param string $phoneNumber
     * @return bool
     */
    public function hasConsent(int $patientId, string $phoneNumber): bool
    {
        $sql = "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId, $phoneNumber]);

        if (!$result) {
            return false;
        }

        return ($result['opted_in'] ?? false) && !($result['opted_out'] ?? false);
    }

    /**
     * Record opt-in and send confirmation message
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @param string $method web_form, portal, in_person, etc
     * @param string|null $ipAddress
     */
    public function optIn(
        int $patientId,
        string $phoneNumber,
        string $method,
        ?string $ipAddress = null
    ): void {
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

        $this->logger->debug("Patient {$patientId} opted in via {$method}");

        try {
            $this->sendOptInConfirmation($patientId, $phoneNumber);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send opt-in confirmation: " . $e->getMessage());
        }
    }

    /**
     * Record opt-out (STOP keyword)
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @param string $method sms_stop, web_form, in_person, etc
     */
    public function optOut(int $patientId, string $phoneNumber, string $method): void
    {
        $sql = "UPDATE oce_sinch_patient_consent
                SET opted_out = TRUE,
                    opt_out_method = ?,
                    opt_out_date = NOW(),
                    updated_at = NOW()
                WHERE patient_id = ? AND phone_number = ?";

        QueryUtils::sqlStatementThrowException($sql, [$method, $patientId, $phoneNumber]);

        $this->logger->debug("Patient {$patientId} opted out via {$method}");
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
        $sql = "SELECT * FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId, $phoneNumber]);

        return $result ?: null;
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

        $this->messageService->sendToPatient($patientId, $phoneNumber, $message, [
            'template_key' => 'opt_in_confirmation',
        ]);
    }
}
