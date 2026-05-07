<?php

/**
 * Renders an SMS-eligibility status line below the patient field on the
 * calendar add/edit appointment form
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Listener;

use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;

/**
 * Subscribes to AppointmentRenderEvent::RENDER_BELOW_PATIENT so staff can see
 * at-a-glance whether the selected patient is eligible to receive SMS
 * appointment reminders — without having to dig through logs after the fact.
 *
 * Scope: this is a patient-level eligibility check (chart hipaa_allowsms,
 * phone parsability, module-side opt-out / carrier block). It does NOT
 * predict whether the reminder cron will actually fire for this specific
 * appointment — that depends on the notification-hours window, whether a
 * reminder was already sent for pc_eid, and whether reminders are enabled
 * in the module config. The label wording reflects that distinction.
 *
 * The pid cascade mirrors interface/main/calendar/add_edit_event.php:
 * the edit-existing flow populates $row['pc_pid'], the new-with-preselect
 * flow puts the pid in $_REQUEST['patientid'], and a stale session pid
 * is the last-resort fallback. When no pid is available (truly blank new
 * appointment), an empty placeholder is emitted so the companion
 * AppointmentSmsStatusJsListener can populate it after the user picks a
 * patient via the popup.
 */
class AppointmentSmsStatusListener
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly MessageService $messageService,
        private readonly EligibilityAlertRenderer $renderer,
    ) {
        $this->logger = new SystemLogger();
    }

    public function onRenderBelowPatient(AppointmentRenderEvent $event): void
    {
        $appt = $event->getAppt();
        $pid = $this->extractPid($appt);
        if ($pid === null) {
            // Empty placeholder is still emitted so the JS layer has a
            // target div to populate when the user picks a patient.
            echo $this->renderer->renderEmpty();
            return;
        }

        // Render is best-effort. A transient diagnose() failure (DB blip, a
        // misconfigured module) must not take down the whole appointment
        // form — emit an empty placeholder (the JS layer can retry on a
        // patient swap) and leave a trace in the log.
        try {
            $verdict = $this->messageService->diagnose($pid);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to diagnose SMS eligibility for calendar render', [
                'patientId' => $pid,
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            echo $this->renderer->renderEmpty();
            return;
        }
        echo $this->renderer->render($verdict);
    }

    /**
     * Resolve the patient id for the appointment about to be rendered.
     *
     * Cascade matches interface/main/calendar/add_edit_event.php lines
     * 793–798 and 830 — the edit-existing flow populates $row['pc_pid']
     * from the database; the new-appointment-with-preselected-patient flow
     * leaves $row empty and puts the pid in $_REQUEST['patientid'], with
     * $_SESSION['pid'] as the last-resort fallback for staff who navigated
     * here straight from a chart.
     *
     * @param array<array-key, mixed> $appt
     */
    private function extractPid(array $appt): ?int
    {
        $pid = $this->coercePid($appt['pc_pid'] ?? null);
        if ($pid !== null) {
            return $pid;
        }

        $pid = $this->coercePid($_REQUEST['patientid'] ?? null);
        if ($pid !== null) {
            return $pid;
        }

        return $this->coercePid($_SESSION['pid'] ?? null);
    }

    private function coercePid(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }
        return null;
    }
}
