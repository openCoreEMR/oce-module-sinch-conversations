<?php

/**
 * Test fixture standing in for OpenEMR's library/appointments.inc.php.
 *
 * Defined as global functions because that's the seam CoreAppointmentFinder
 * uses (`require_once $fileroot . '/library/appointments.inc.php'` then
 * `\fetchAppointments(...)`). The fixture lets a unit test:
 *
 *  - control what fetchAppointments returns by setting
 *    $GLOBALS['__test_fetch_appointments_return'] (array of event rows)
 *  - inspect what arguments fetchAppointments was called with via
 *    $GLOBALS['__test_fetch_appointments_calls']
 *  - catch any regression that calls fetchAllEvents instead — the stub
 *    throws, which would surface as a test failure rather than a silent
 *    "wrong calendar feed" the way it did in 1.2.0
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

if (!function_exists('fetchAppointments')) {
    function fetchAppointments(
        $from_date,
        $to_date,
        $patient_id = null,
        $provider_id = null,
        $facility_id = null,
        $pc_appstatus = null,
        $with_out_provider = null,
        $with_out_facility = null,
        $pc_catid = null,
        $tracker_board = false,
        $nextX = 0,
        $group_id = null,
        $patient_name = null
    ) {
        $GLOBALS['__test_fetch_appointments_calls'][] = [
            'from_date' => $from_date,
            'to_date' => $to_date,
        ];
        return $GLOBALS['__test_fetch_appointments_return'] ?? [];
    }
}

if (!function_exists('fetchAllEvents')) {
    function fetchAllEvents($from_date, $to_date, $provider_id = null, $facility_id = null)
    {
        // Regression guard. fetchAllEvents returns provider-availability
        // blocks ("In Office" / "Out Of Office") with pc_pid empty — never
        // patient appointments. CoreAppointmentFinder used this in 1.2.0
        // and reminders silently stopped firing. Fail loudly if anyone
        // wires it back up.
        throw new \RuntimeException(
            'CoreAppointmentFinder must call fetchAppointments(), not fetchAllEvents() — '
            . 'fetchAllEvents returns provider-availability blocks, not patient appointments.'
        );
    }
}
