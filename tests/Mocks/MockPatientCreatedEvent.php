<?php

/**
 * Mock PatientCreatedEvent for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Events\Patient;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Minimal stand-in for OpenEMR's PatientCreatedEvent. See issue #118 and
 * tools/openemr/README.md.
 */
class PatientCreatedEvent extends Event
{
    public const EVENT_HANDLE = 'patient.created';

    /**
     * @param array<string, mixed> $patientData
     */
    public function __construct(private array $patientData)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPatientData(): array
    {
        return $this->patientData;
    }

    /**
     * @param array<string, mixed> $patientData
     */
    public function setPatientData(array $patientData): void
    {
        $this->patientData = $patientData;
    }
}
