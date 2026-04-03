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

declare(strict_types=1);

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
     * Process inbound message for keywords (webhook path)
     *
     * Use this when patient ID is not known upfront. Finds patients by
     * phone number lookup.
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
                $this->handleStart($normalized, (int) $patients[0]['pid']),
            'HELP', 'INFO' =>
                $this->handleHelp(),
            default => null,
        };
    }

    /**
     * Process inbound message for keywords with a known patient ID (polling path)
     *
     * Use this when the caller already has the authoritative patient ID
     * (e.g. from the conversation record). Skips the phone-number-based
     * patient lookup for START, and guarantees the known patient is included
     * in STOP even if the phone format doesn't match the database.
     *
     * STOP still applies to ALL patients sharing the number (carrier-level),
     * plus the known patient as a guarantee.
     * START applies to the known patient directly (no first-match ambiguity).
     *
     * @param int $patientId Known patient ID from the conversation record
     * @param string $phoneNumber E.164 phone number
     * @param string $messageBody
     * @return string|null Response message, or null if not a keyword
     */
    public function handleInboundMessageForPatient(
        int $patientId,
        string $phoneNumber,
        string $messageBody
    ): ?string {
        $keyword = $this->detectKeyword($messageBody);

        if ($keyword === null) {
            return null;
        }

        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            $this->logger->warning('Could not normalize phone number', ['phone' => $phoneNumber]);
            return null;
        }

        return match (strtoupper($keyword)) {
            'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' =>
                $this->handleStopWithKnownPatient($normalized, $patientId),
            'START', 'UNSTOP', 'SUBSCRIBE' =>
                $this->handleStart($normalized, $patientId),
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
     * Handle STOP keyword with a known patient guarantee
     *
     * Opt out all patients found by phone lookup (carrier-level), plus
     * ensure the known patient is opted out even if the phone lookup
     * missed them due to format mismatches or missing phone_cell.
     *
     * @param string $phoneNumber E.164
     * @param int $knownPatientId Authoritative patient ID from the conversation
     * @return string Response message
     */
    private function handleStopWithKnownPatient(string $phoneNumber, int $knownPatientId): string
    {
        $patients = $this->findPatientsByPhone($phoneNumber);

        $knownPatientFound = false;
        foreach ($patients as $patient) {
            $pid = (int) $patient['pid'];
            $this->consentService->optOut($pid, $phoneNumber, 'sms_stop');
            if ($pid === $knownPatientId) {
                $knownPatientFound = true;
            }
        }

        if (!$knownPatientFound) {
            $this->consentService->optOut($knownPatientId, $phoneNumber, 'sms_stop');
        }

        $variables = [
            'clinic_name' => $this->config->getClinicName(),
            'phone' => $this->config->getClinicPhone(),
        ];

        return $this->templateService->render('keyword_stop', $variables);
    }

    /**
     * Handle START keyword -- opt-in the given patient
     *
     * From the webhook path this is the first phone-lookup match (since
     * a keyword alone cannot disambiguate). From the polling path this
     * is the authoritative patient from the conversation record.
     *
     * @param string $phoneNumber E.164
     * @param int $patientId Patient to opt in
     * @return string Response message
     */
    private function handleStart(string $phoneNumber, int $patientId): string
    {
        $this->consentService->optIn($patientId, $phoneNumber, 'sms_start', null);

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
