<?php

/**
 * Renders the SMS-eligibility alert markup shared between the appointment
 * form's server-rendered initial paint and the JS-fetched re-render
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Render;

use OpenCoreEMR\Modules\SinchConversations\SkipReason;

/**
 * Single source of truth for the calendar SMS-eligibility badge markup.
 *
 * The same alert is produced two ways: once server-side at appointment-form
 * render time (so the badge is present on first paint) and again by the JS
 * layer when staff swap patients via the popup picker. Keeping the markup
 * here means xlt() translation and HTML structure cannot drift between the
 * two paths.
 */
class EligibilityAlertRenderer
{
    /**
     * DOM id of the wrapper div the JS layer targets when replacing the
     * alert after a patient swap. Server-rendered output and the
     * empty-placeholder fallback both use this id so the JS lookup
     * succeeds in either case.
     */
    public const PLACEHOLDER_ID = 'oce-sinch-eligibility-status';

    /**
     * Render the verdict as a Bootstrap alert wrapped in the placeholder div.
     *
     * Translated literals go through xlt() (translate + HTML-escape via
     * OpenEMR's i18n helpers). The unknown-reason fallback is the only
     * value that can carry untrusted input (a future SkipReason token), so
     * it gets an explicit htmlspecialchars to make the escape obvious.
     *
     * @param array{
     *     can_send: bool,
     *     reason: ?string,
     *     context: array<string, scalar|null>,
     *     phone: ?string
     * } $verdict
     */
    public function render(array $verdict): string
    {
        $canSend = $verdict['can_send'];
        $cssClass = $canSend ? 'alert-success' : 'alert-warning';
        // Phrased as patient-level eligibility, not as a per-appointment
        // send prediction — the cron has additional checks (notification
        // window, dedup) that this surface does not consult.
        $headline = $canSend
            ? xlt('Patient is eligible to receive SMS appointment reminders.')
            : xlt('Patient is not eligible to receive SMS appointment reminders.');

        $detail = $canSend ? '' : $this->reasonLabel($verdict['reason']);

        $inner = '<div class="alert ' . $cssClass . ' mt-2 py-1 mb-0" role="status">'
            . '<strong>' . $headline . '</strong>';
        if ($detail !== '') {
            $inner .= ' ' . $detail;
        }
        $inner .= '</div>';

        return $this->wrap($inner);
    }

    /**
     * Render just the empty placeholder div. Used when no pid is available
     * (new-appointment flow without a preselected patient) so the JS layer
     * always has a target element to populate after the user picks a
     * patient.
     */
    public function renderEmpty(): string
    {
        return $this->wrap('');
    }

    private function wrap(string $inner): string
    {
        return '<div id="' . self::PLACEHOLDER_ID . '">' . $inner . '</div>';
    }

    /**
     * Translate the structured SkipReason value into a staff-facing reason
     * line. Unknown values fall back to the raw token (escaped) so a future
     * reason surfaces as something rather than nothing.
     */
    private function reasonLabel(?string $reason): string
    {
        if ($reason === null) {
            return '';
        }

        return match ($reason) {
            SkipReason::HipaaDisallowsSms->value => xlt(
                'Reason: the patient chart has Allow SMS set to No (or unset).'
            ),
            SkipReason::MissingPhone->value => xlt(
                'Reason: no mobile phone number is on file for this patient.'
            ),
            SkipReason::UnparseablePhone->value => xlt(
                "Reason: the patient's mobile phone number could not be parsed."
            ),
            SkipReason::ModuleOptOut->value => xlt(
                'Reason: the patient has explicitly opted out of SMS messages.'
            ),
            SkipReason::CarrierBlocked->value => xlt(
                "Reason: the patient's mobile carrier has blocked messages from us."
            ),
            default => xlt('Reason:') . ' ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'),
        };
    }
}
