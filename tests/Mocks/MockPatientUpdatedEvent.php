<?php

/**
 * Mock PatientUpdatedEvent for testing
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
 * Minimal stand-in for OpenEMR's PatientUpdatedEvent. See issue #118 and
 * tools/openemr/README.md.
 */
class PatientUpdatedEvent extends Event
{
    public const EVENT_HANDLE = 'patient.updated';

    /**
     * @param array<string, mixed> $dataBeforeUpdate
     * @param array<string, mixed> $newPatientData
     */
    public function __construct(private array $dataBeforeUpdate, private array $newPatientData)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDataBeforeUpdate(): array
    {
        return $this->dataBeforeUpdate;
    }

    /**
     * @param array<string, mixed> $dataBeforeUpdate
     */
    public function setDataBeforeUpdate(array $dataBeforeUpdate): void
    {
        $this->dataBeforeUpdate = $dataBeforeUpdate;
    }

    /**
     * @return array<string, mixed>
     */
    public function getNewPatientData(): array
    {
        return $this->newPatientData;
    }

    /**
     * @param array<string, mixed> $newPatientData
     */
    public function setNewPatientData(array $newPatientData): void
    {
        $this->newPatientData = $newPatientData;
    }
}
