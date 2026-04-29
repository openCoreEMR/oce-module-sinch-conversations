<?php

/**
 * Appointment Reminder Cron Service
 *
 * Query upcoming appointments within the configured notification window,
 * check patient eligibility, and send reminders via Sinch. Track sent
 * reminders to avoid duplicates.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class AppointmentReminderService
{
    /**
     * Stable reason codes emitted when a reminder is skipped or fails.
     * Kept stable across releases so log consumers (alerts, dashboards)
     * can pivot on them.
     */
    public const REASON_MISSING_PHONE = 'missing_phone';
    public const REASON_UNPARSEABLE_PHONE = 'unparseable_phone';
    public const REASON_HIPAA_DISALLOWS_SMS = 'hipaa_disallows_sms';
    public const REASON_NO_ACTIVE_CONSENT = 'no_active_consent';

    /**
     * Sub-states for REASON_NO_ACTIVE_CONSENT to aid triage.
     */
    private const CONSENT_STATE_ACTIVE = 'active';
    private const CONSENT_STATE_NONE = 'none';
    private const CONSENT_STATE_OPTED_OUT = 'opted_out';
    private const CONSENT_STATE_NOT_OPTED_IN = 'not_opted_in';

    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly TemplateService $templateService,
        private readonly MessageService $messageService
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Run the appointment reminder job
     *
     * Find upcoming appointments within the notification window, skip patients
     * who are ineligible or already reminded, and send reminders for the rest.
     *
     * @return array{sent: int, skipped: int, failed: int, errors: list<string>}
     */
    public function run(): array
    {
        $results = [
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $this->purgeExpiredReminders();

        $hours = $this->config->getSmsNotificationHours();
        if ($hours <= 0) {
            $this->logger->debug('SMS notification hours is 0 or negative, skipping appointment reminders');
            return $results;
        }

        $appointments = $this->getUpcomingAppointments($hours);
        if ($appointments === []) {
            $this->logger->debug('No upcoming appointments found within notification window');
            return $results;
        }

        $templateKey = $this->templateService->getAppointmentReminderTemplateKey();

        foreach ($appointments as $appointment) {
            $pcEid = (int) $appointment['pc_eid'];
            $patientId = (int) $appointment['pc_pid'];

            $rawPhone = $appointment['phone_cell'] ?? '';
            if ($rawPhone === '') {
                $this->logSkip($pcEid, $patientId, self::REASON_MISSING_PHONE);
                $results['skipped']++;
                continue;
            }

            $phoneNumber = PhoneNormalizer::toE164($rawPhone);
            if ($phoneNumber === null) {
                $this->logSkip($pcEid, $patientId, self::REASON_UNPARSEABLE_PHONE, [
                    'phone_last4' => PhoneNormalizer::last4($rawPhone),
                ]);
                $results['skipped']++;
                continue;
            }

            $hipaaAllowSms = (string) ($appointment['hipaa_allowsms'] ?? '');
            if ($hipaaAllowSms !== 'YES') {
                $this->logSkip($pcEid, $patientId, self::REASON_HIPAA_DISALLOWS_SMS, [
                    'hipaa_allowsms' => $hipaaAllowSms === '' ? 'unset' : $hipaaAllowSms,
                ]);
                $results['skipped']++;
                continue;
            }

            $consentState = $this->getConsentState($patientId, $phoneNumber);
            if ($consentState !== self::CONSENT_STATE_ACTIVE) {
                $this->logSkip($pcEid, $patientId, self::REASON_NO_ACTIVE_CONSENT, [
                    'consent_state' => $consentState,
                ]);
                $results['skipped']++;
                continue;
            }

            $variables = $this->buildTemplateVariables($appointment);

            try {
                $message = $this->templateService->render($templateKey, $variables);
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "Event {$pcEid}: template render failed: " . $e->getMessage();
                $this->logger->error('Appointment reminder template render failed', [
                    'pc_eid' => $pcEid,
                    'exception' => $e,
                ]);
                continue;
            }

            try {
                $this->messageService->sendToPatient(
                    $patientId,
                    $phoneNumber,
                    $message,
                    new MessageOptions(templateKey: $templateKey, skipConsentCheck: true)
                );
                $this->recordReminderSent($pcEid, $patientId, $templateKey);
                $results['sent']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "Event {$pcEid}: send failed: " . $e->getMessage();
                $this->logger->error('Appointment reminder send failed', [
                    'pc_eid' => $pcEid,
                    'patient_id' => $patientId,
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->info('Appointment reminder job completed', [
            'sent' => $results['sent'],
            'skipped' => $results['skipped'],
            'failed' => $results['failed'],
        ]);

        return $results;
    }

    /**
     * Query upcoming appointments within the notification window
     *
     * Find events from openemr_postcalendar_events where the appointment
     * datetime is between now and now + $hours hours, and the event is
     * not cancelled (pc_apptstatus != 'x').
     *
     * @return array<int, array<string, mixed>>
     */
    private function getUpcomingAppointments(int $hours): array
    {
        $sql = "SELECT e.pc_eid, e.pc_pid, e.pc_eventDate, e.pc_startTime,
                       p.phone_cell, p.hipaa_allowsms
                FROM openemr_postcalendar_events e
                JOIN patient_data p ON e.pc_pid = p.pid
                LEFT JOIN oce_sinch_appointment_reminders r ON e.pc_eid = r.pc_eid
                WHERE CONCAT(e.pc_eventDate, ' ', e.pc_startTime) > NOW()
                  AND CONCAT(e.pc_eventDate, ' ', e.pc_startTime) <= DATE_ADD(NOW(), INTERVAL ? HOUR)
                  AND e.pc_apptstatus != 'x'
                  AND e.pc_pid > 0
                  AND r.id IS NULL
                ORDER BY e.pc_eventDate, e.pc_startTime";

        return QueryUtils::fetchRecords($sql, [$hours]);
    }

    /**
     * Record that a reminder was sent for this event
     *
     * Use INSERT IGNORE to handle race conditions where concurrent cron runs
     * pass the LEFT JOIN check and both attempt to insert. The UNIQUE KEY on
     * pc_eid ensures only one succeeds; the other is silently ignored.
     */
    private function recordReminderSent(int $pcEid, int $patientId, string $templateKey): void
    {
        $sql = "INSERT IGNORE INTO oce_sinch_appointment_reminders
                    (pc_eid, patient_id, sent_at, template_key)
                VALUES (?, ?, NOW(), ?)";
        QueryUtils::sqlStatementThrowException($sql, [$pcEid, $patientId, $templateKey]);
    }

    /**
     * Delete reminder records older than 90 days
     *
     * These records only serve as deduplication guards. Once the appointment
     * date has long passed, they are no longer needed.
     */
    private function purgeExpiredReminders(): void
    {
        try {
            $sql = "DELETE FROM oce_sinch_appointment_reminders WHERE sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
            QueryUtils::sqlStatementThrowException($sql, []);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to purge expired appointment reminders', ['exception' => $e]);
        }
    }

    /**
     * Classify module-level consent into a stable state for logging and gating.
     *
     * The hipaa_allowsms check is handled in run() from the main query result.
     * This method checks only module-level consent in oce_sinch_patient_consent
     * and returns one of:
     * - CONSENT_STATE_ACTIVE: opted in and not opted out
     * - CONSENT_STATE_NONE: no consent row exists for this patient/phone
     * - CONSENT_STATE_OPTED_OUT: row exists with opted_out=true
     * - CONSENT_STATE_NOT_OPTED_IN: row exists with opted_in=false
     *
     * @param string $phoneNumber E.164 normalized phone number
     */
    private function getConsentState(int $patientId, string $phoneNumber): string
    {
        $sql = "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        $consent = QueryUtils::querySingleRow($sql, [$patientId, $phoneNumber]);

        if ($consent === null) {
            return self::CONSENT_STATE_NONE;
        }

        if ((bool) ($consent['opted_out'] ?? false)) {
            return self::CONSENT_STATE_OPTED_OUT;
        }

        if (!(bool) ($consent['opted_in'] ?? false)) {
            return self::CONSENT_STATE_NOT_OPTED_IN;
        }

        return self::CONSENT_STATE_ACTIVE;
    }

    /**
     * Emit a structured warning that a per-appointment skip decision was made.
     *
     * Every skip path increments the `skipped` counter; this helper makes the
     * decision visible at WARNING level so a clinic seeing zero reminders can
     * tell whether the cron is firing and why each appointment was dropped.
     *
     * @param array<string, scalar|null> $extra additional cheap context to aid triage
     */
    private function logSkip(int $pcEid, int $patientId, string $reason, array $extra = []): void
    {
        $this->logger->warning('Appointment reminder skipped', [
            'pc_eid' => $pcEid,
            'patient_id' => $patientId,
            'reason' => $reason,
        ] + $extra);
    }

    /**
     * Build template variables for an appointment
     *
     * @param array<string, mixed> $appointment
     * @return array<string, string>
     */
    private function buildTemplateVariables(array $appointment): array
    {
        $date = (string) ($appointment['pc_eventDate'] ?? '');
        $time = (string) ($appointment['pc_startTime'] ?? '');
        $rawDatetime = $date . ' ' . $time;

        try {
            $dt = new \DateTimeImmutable($rawDatetime);
            $apptTime = $dt->format('l, M j, Y \a\t g:i A');
        } catch (\Throwable) {
            $apptTime = $rawDatetime;
        }

        $variables = [
            'clinic_name' => $this->config->getClinicName(),
            'appt_time' => $apptTime,
            'opt_out' => 'Reply STOP to opt out',
        ];

        if ($this->config->isPortalEnabled()) {
            $variables['portal_url'] = $this->config->getPortalUrl();
        }

        return $variables;
    }
}
