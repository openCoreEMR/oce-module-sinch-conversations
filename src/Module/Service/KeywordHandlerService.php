<?php

/**
 * Keyword Handler Service for HELP/STOP/START keywords
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

class KeywordHandlerService
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ConsentService $consentService,
        private readonly TemplateService $templateService
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Process inbound message for keywords
     *
     * @param string $fromNumber
     * @param string $messageBody
     * @return string|null Response message, or null if not a keyword
     */
    public function handleInboundMessage(string $fromNumber, string $messageBody): ?string
    {
        $keyword = $this->detectKeyword($messageBody);

        if (!$keyword) {
            return null;
        }

        $patient = $this->findPatientByPhone($fromNumber);

        if (!$patient) {
            $this->logger->warning("Received keyword from unknown number: {$fromNumber}");
            return null;
        }

        return match (strtoupper($keyword)) {
            'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' =>
                $this->handleStop($fromNumber, $patient),
            'START', 'UNSTOP', 'SUBSCRIBE' =>
                $this->handleStart($fromNumber, $patient),
            'HELP', 'INFO' =>
                $this->handleHelp(),
            default => null,
        };
    }

    /**
     * Detect if message contains a keyword
     *
     * @param string $messageBody
     * @return string|null Keyword found, or null
     */
    private function detectKeyword(string $messageBody): ?string
    {
        $keywords = [
            'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT',
            'START', 'UNSTOP', 'SUBSCRIBE',
            'HELP', 'INFO',
        ];

        $normalized = strtoupper(trim($messageBody));

        foreach ($keywords as $keyword) {
            if ($normalized === $keyword) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * Handle STOP keyword
     *
     * @param string $phoneNumber
     * @param array<string, mixed> $patient
     * @return string Response message
     */
    private function handleStop(string $phoneNumber, array $patient): string
    {
        $this->consentService->optOut((int)$patient['pid'], $phoneNumber, 'sms_stop');

        $variables = [
            'clinic_name' => $this->config->getClinicName(),
            'phone' => $this->config->getClinicPhone(),
        ];

        return $this->templateService->render('keyword_stop', $variables);
    }

    /**
     * Handle START keyword
     *
     * @param string $phoneNumber
     * @param array<string, mixed> $patient
     * @return string Response message
     */
    private function handleStart(string $phoneNumber, array $patient): string
    {
        $this->consentService->optIn((int)$patient['pid'], $phoneNumber, 'sms_start', null);

        $variables = [
            'clinic_name' => $this->config->getClinicName(),
        ];

        return $this->templateService->render('keyword_start', $variables);
    }

    /**
     * Handle HELP keyword
     *
     * @return string Response message
     */
    private function handleHelp(): string
    {
        $variables = [
            'clinic_name' => $this->config->getClinicName(),
            'phone' => $this->config->getClinicPhone(),
        ];

        return $this->templateService->render('keyword_help', $variables);
    }

    /**
     * Find patient by phone number
     *
     * @param string $phoneNumber
     * @return array<string, mixed>|null
     */
    private function findPatientByPhone(string $phoneNumber): ?array
    {
        $normalized = preg_replace('/[^0-9]/', '', $phoneNumber);

        $sql = "SELECT pid, fname, lname, phone_cell
                FROM patient_data
                WHERE REPLACE(REPLACE(REPLACE(phone_cell, '-', ''), ' ', ''), '+', '') LIKE ?
                LIMIT 1";

        $result = QueryUtils::querySingleRow($sql, ['%' . $normalized]);

        return $result ?: null;
    }
}
