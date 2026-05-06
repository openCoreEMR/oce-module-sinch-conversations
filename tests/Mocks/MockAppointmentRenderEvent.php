<?php

/**
 * Mock AppointmentRenderEvent for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Events\Appointments;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Minimal stand-in for OpenEMR's AppointmentRenderEvent. See issue #118 and
 * tools/openemr/README.md.
 */
class AppointmentRenderEvent extends Event
{
    public const RENDER_JAVASCRIPT = 'appointment.render.javascript';
    public const RENDER_BELOW_PATIENT = 'appointment.render.below.patient';
    public const RENDER_BEFORE_ACTION_BAR = 'appointment.render.action-bar.before';

    /**
     * @param array<array-key, mixed> $appt
     */
    public function __construct(private array $appt)
    {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getAppt(): array
    {
        return $this->appt;
    }
}
