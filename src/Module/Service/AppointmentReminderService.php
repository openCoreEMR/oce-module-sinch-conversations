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

use OpenCoreEMR\Modules\SinchConversations\ConsentBlock;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Modules\SinchConversations\Schema\ReminderTableMigration;
use OpenCoreEMR\Modules\SinchConversations\SkipReason;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class AppointmentReminderService
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly TemplateService $templateService,
        private readonly MessageService $messageService,
        private readonly UpcomingAppointmentFinder $finder
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Run the appointment reminder job
     *
     * Find upcoming appointments within the notification window, skip patients
     * who are ineligible or already reminded, and send reminders for the rest.
     *
     * @param \DateTimeImmutable|null $now Wall-clock for the run. Tests pass
     *     a fixed instant; production callers (background_service_entry)
     *     leave null and get the current time.
     * @return array{sent: int, skipped: int, failed: int, errors: list<string>}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $results = [
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Purge first: it only references `sent_at`, which exists in
        // both the old and new schemas, so it doesn't depend on the
        // migration succeeding. Running it first means a tenant blocked
        // on a failing migration still gets stale-row cleanup.
        $this->purgeExpiredReminders();

        // Safety net for tenants who upgrade the module code without
        // disabling/re-enabling the module. enable() also calls this; both
        // paths short-circuit once the table is fully migrated. We catch
        // here so a lock-timeout or DDL error doesn't crash the cron
        // runner — the next tick will retry. background_service_entry has
        // no try/catch around run(), so this is the failure boundary.
        try {
            ReminderTableMigration::ensureUpgraded();
        } catch (\Throwable $e) {
            $results['failed']++;
            $results['errors'][] = 'schema migration failed: ' . $e->getMessage();
            $this->logger->error('Appointment reminder schema migration failed', [
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            return $results;
        }

        $hours = $this->config->getSmsNotificationHours();
        if ($hours <= 0) {
            $this->logger->debug('SMS notification hours is 0 or negative, skipping appointment reminders');
            return $results;
        }

        $appointments = $this->finder->findUpcoming($hours, $now);
        if ($appointments === []) {
            $this->logger->debug('No upcoming appointments found within notification window');
            return $results;
        }

        $sentKeys = $this->loadSentOccurrences(
            $now->format('Y-m-d'),
            $now->modify(sprintf('+%d hours', $hours))->format('Y-m-d')
        );

        $templateKey = $this->templateService->getAppointmentReminderTemplateKey();

        foreach ($appointments as $appointment) {
            // UpcomingAppointmentFinder declares the row shape with typed
            // fields; PHPStan enforces it on every implementation, so no
            // narrowing is needed here.
            $pcEid = $appointment['pc_eid'];
            $patientId = $appointment['pc_pid'];
            $occurrenceDate = $appointment['pc_eventDate'];

            if (isset($sentKeys[self::dedupKey($pcEid, $occurrenceDate)])) {
                // Already reminded for this specific occurrence — quiet skip.
                continue;
            }

            $rawPhone = $appointment['phone_cell'];
            if ($rawPhone === null || $rawPhone === '') {
                $this->logSkip($pcEid, $patientId, SkipReason::MissingPhone);
                $results['skipped']++;
                continue;
            }

            $phoneNumber = PhoneNormalizer::toE164($rawPhone);
            if ($phoneNumber === null) {
                $this->logSkip($pcEid, $patientId, SkipReason::UnparseablePhone, [
                    'phone_last4' => PhoneNormalizer::last4($rawPhone),
                ]);
                $results['skipped']++;
                continue;
            }

            $hipaaAllowSms = $appointment['hipaa_allowsms'] ?? '';
            if ($hipaaAllowSms !== 'YES') {
                $this->logSkip($pcEid, $patientId, SkipReason::HipaaDisallowsSms, [
                    'hipaa_allowsms' => $hipaaAllowSms === '' ? 'unset' : $hipaaAllowSms,
                ]);
                $results['skipped']++;
                continue;
            }

            // Chart hipaa_allowsms='YES' (checked above) is the source of
            // truth for opt-in. Module table is consulted only for explicit
            // blocks (patient opt-out via STOP, carrier blocks, etc.).
            // Absence of a row is fine — it just means no block on file.
            $block = ConsentBlock::evaluate($this->getConsentRow($patientId, $phoneNumber));
            if ($block !== null) {
                $this->logSkip($pcEid, $patientId, $block->reason, $block->context);
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
                    'exception' => ExceptionContext::fromThrowable($e),
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
                $this->recordReminderSent($pcEid, $patientId, $occurrenceDate, $templateKey);
                // Mark the in-memory dedup map too, so a duplicate
                // (pc_eid, occurrence_date) later in this same run (e.g.
                // a finder that returns the same occurrence twice) is
                // skipped instead of double-sent. The DB INSERT IGNORE
                // dedups the log row, not the SMS delivery.
                $sentKeys[self::dedupKey($pcEid, $occurrenceDate)] = true;
                $results['sent']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "Event {$pcEid}: send failed: " . $e->getMessage();
                $this->logger->error('Appointment reminder send failed', [
                    'pc_eid' => $pcEid,
                    'patient_id' => $patientId,
                    'exception' => ExceptionContext::fromThrowable($e),
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
     * Bulk-load already-sent reminder keys for the active window.
     *
     * Returned map keys are `"{pc_eid}|{occurrence_date}"`; values are
     * irrelevant (presence is the signal). One round trip up front
     * supplies the dedup state for every appointment in the window —
     * cheaper than a per-occurrence SELECT and necessary because the
     * old `LEFT JOIN ... r.id IS NULL` filter no longer fits now that
     * the dedup key is `(pc_eid, occurrence_date)` and the appointment
     * source has moved out of SQL into the finder.
     *
     * @return array<string, true>
     */
    private function loadSentOccurrences(string $fromDate, string $toDate): array
    {
        $sql = "SELECT pc_eid, occurrence_date
                FROM oce_sinch_appointment_reminders
                WHERE occurrence_date BETWEEN ? AND ?";
        $rows = QueryUtils::fetchRecords($sql, [$fromDate, $toDate]);

        $map = [];
        foreach ($rows as $row) {
            // Schema guarantees both columns are non-null; a row that
            // disagrees is a real bug — drop it and log instead of
            // silently coercing it into a misshapen dedup key.
            $pcEidRaw = $row['pc_eid'] ?? null;
            $pcEid = is_int($pcEidRaw)
                ? $pcEidRaw
                : (is_string($pcEidRaw)
                    ? filter_var($pcEidRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false);
            if (!is_int($pcEid)) {
                $this->logger->warning(
                    'Skipping dedup row: pc_eid is not a positive integer',
                    ['raw_pc_eid_type' => get_debug_type($pcEidRaw)]
                );
                continue;
            }
            $occurrenceDate = $row['occurrence_date'] ?? null;
            if (!is_string($occurrenceDate) || $occurrenceDate === '') {
                $this->logger->warning(
                    'Skipping dedup row: occurrence_date is not a non-empty string',
                    ['raw_occurrence_date_type' => get_debug_type($occurrenceDate)]
                );
                continue;
            }
            $map[self::dedupKey($pcEid, $occurrenceDate)] = true;
        }
        return $map;
    }

    /**
     * Build the in-memory dedup map key for a single occurrence.
     *
     * Centralised so the format can change without touching three call
     * sites (loadSentOccurrences, the eligibility check, the in-loop
     * mark after a successful send).
     */
    private static function dedupKey(int $pcEid, string $occurrenceDate): string
    {
        return $pcEid . '|' . $occurrenceDate;
    }

    /**
     * Record that a reminder was sent for a specific occurrence.
     *
     * The UNIQUE KEY on `(pc_eid, occurrence_date)` exists so re-runs of
     * the cron — same process, next tick — never insert a second log row
     * for an occurrence already sent. It does NOT make the
     * send-then-record sequence atomic: if two processes ever raced past
     * the bulk dedup check at the top of run(), both would send before
     * either reached this insert. The actual concurrency guard for that
     * scenario lives in OpenEMR's `background_services.running` flag,
     * which serializes cron invocations of this service.
     *
     * INSERT IGNORE keeps the call idempotent under that re-run pattern
     * (and tolerates a pessimistic re-record from any future retry path)
     * by silently dropping a duplicate-key collision instead of throwing.
     * Recurring appointments share `pc_eid` across their occurrences, so
     * `occurrence_date` is what distinguishes them.
     */
    private function recordReminderSent(
        int $pcEid,
        int $patientId,
        string $occurrenceDate,
        string $templateKey
    ): void {
        $sql = "INSERT IGNORE INTO oce_sinch_appointment_reminders
                    (pc_eid, occurrence_date, patient_id, sent_at, template_key)
                VALUES (?, ?, ?, NOW(), ?)";
        QueryUtils::sqlStatementThrowException(
            $sql,
            [$pcEid, $occurrenceDate, $patientId, $templateKey]
        );
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
            $this->logger->error(
                'Failed to purge expired appointment reminders',
                ['exception' => ExceptionContext::fromThrowable($e)]
            );
        }
    }

    /**
     * Read the module-side exception row for a patient/phone, or null if none.
     *
     * The chart hipaa_allowsms check (the actual opt-in signal) is handled
     * in run() from the main query result. This method only inspects the
     * module's exception store so callers can detect explicit opt-outs and
     * carrier blocks that should override the chart's YES.
     *
     * @param string $phoneNumber E.164 normalized phone number
     * @return array<string, mixed>|null
     */
    private function getConsentRow(int $patientId, string $phoneNumber): ?array
    {
        $sql = "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        // QueryUtils::querySingleRow returns false on no row; normalize so
        // the declared ?array return holds.
        return QueryUtils::querySingleRow($sql, [$patientId, $phoneNumber]) ?: null;
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
    private function logSkip(int $pcEid, int $patientId, SkipReason $reason, array $extra = []): void
    {
        $this->logger->warning('Appointment reminder skipped', [
            'pc_eid' => $pcEid,
            'patient_id' => $patientId,
            'reason' => $reason->value,
        ] + $extra);
    }

    /**
     * Build template variables for an appointment.
     *
     * @param array{
     *     pc_eid: int,
     *     pc_pid: int,
     *     pc_eventDate: string,
     *     pc_startTime: string,
     *     phone_cell: ?string,
     *     hipaa_allowsms: ?string
     * } $appointment
     * @return array<string, string>
     */
    private function buildTemplateVariables(array $appointment): array
    {
        $rawDatetime = $appointment['pc_eventDate'] . ' ' . $appointment['pc_startTime'];

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
