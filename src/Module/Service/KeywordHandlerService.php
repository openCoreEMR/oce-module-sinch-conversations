<?php

/**
 * Keyword Handler Service for HELP/STOP/START keywords
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
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
     * STOP/START/HELP operate at the phone-number level, not the patient level.
     * A phone number can belong to multiple patients (e.g. parent with children).
     * STOP applies consent to ALL patients sharing the number.
     * START applies consent only to the specific patient who opted in (first match,
     * since we cannot disambiguate from a keyword alone).
     *
     * @param string $fromNumber E.164 phone number from Sinch
     * @param string $messageBody
     * @return string|null Response message, or null if not a keyword
     */
    public function handleInboundMessage(string $fromNumber, string $messageBody): ?string
    {
        $keyword = $this->detectKeyword($messageBody);

        if ($keyword === null) {
            return null;
        }

        $normalized = PhoneNormalizer::toE164($fromNumber);
        if ($normalized === null) {
            $this->logger->warning("Could not normalize phone number: {$fromNumber}");
            return null;
        }

        $patients = $this->findPatientsByPhone($normalized);

        if ($patients === []) {
            $this->logger->warning('Received keyword from unknown number', ['phone' => $normalized]);
            return null;
        }

        return match (strtoupper($keyword)) {
            'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' =>
                $this->handleStop($normalized, $patients),
            'START', 'UNSTOP', 'SUBSCRIBE' =>
                $this->handleStart($normalized, $patients[0]),
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
     * Handle STOP keyword -- apply opt-out to ALL patients sharing this number
     *
     * Carriers and TCPA operate at the number level: when a number sends STOP,
     * the carrier blocks the number, not a specific person.
     *
     * @param string $phoneNumber E.164
     * @param list<array<string, mixed>> $patients
     * @return string Response message
     */
    private function handleStop(string $phoneNumber, array $patients): string
    {
        foreach ($patients as $patient) {
            $this->consentService->optOut((int) $patient['pid'], $phoneNumber, 'sms_stop');
        }

        $variables = [
            'clinic_name' => $this->config->getClinicName(),
            'phone' => $this->config->getClinicPhone(),
        ];

        return $this->templateService->render('keyword_stop', $variables);
    }

    /**
     * Handle START keyword -- opt-in only the first matched patient
     *
     * START cannot disambiguate which patient the sender means, so only
     * the first match is opted in. Staff can opt in additional patients
     * via the web form.
     *
     * @param string $phoneNumber E.164
     * @param array<string, mixed> $patient
     * @return string Response message
     */
    private function handleStart(string $phoneNumber, array $patient): string
    {
        $this->consentService->optIn((int) $patient['pid'], $phoneNumber, 'sms_start', null);

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
     * Find all patients whose phone_cell matches the given E.164 number
     *
     * Compare by stripping non-digits from both sides and matching the
     * trailing 10 digits (the national number without country code).
     *
     * @param string $phoneNumber E.164 normalized number
     * @return list<array<string, mixed>>
     */
    private function findPatientsByPhone(string $phoneNumber): array
    {
        // Extract the last 10 digits (national number) for matching
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);
        $national = substr((string) $digits, -10);

        // Strip common formatting chars and compare last 10 digits.
        $stripPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE("
            . "phone_cell, '-', ''), ' ', ''), '(', ''), ')', ''), '.', '')";
        $sql = "SELECT pid, fname, lname, phone_cell
                FROM patient_data
                WHERE RIGHT({$stripPhone}, 10) = ?
                AND phone_cell IS NOT NULL
                AND phone_cell != ''
                ORDER BY pid ASC";

        return QueryUtils::fetchRecords($sql, [$national]);
    }
}
