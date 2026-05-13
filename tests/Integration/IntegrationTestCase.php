<?php

/**
 * Base TestCase for the integration suite.
 *
 * Inserts patient/appointment fixture rows directly into patient_data and
 * openemr_postcalendar_events via QueryUtils (the upstream BaseFixtureManager
 * pattern), then exercises the real reminder pipeline — including core
 * OpenEMR's procedural fetchAllEvents/fetchAppointments — against those
 * rows. Both setUp and tearDown scrub OpenEMR-owned fixture rows by prefix
 * and module-owned rows by tracked patient id, so a previous interrupted
 * run cannot pollute this one.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Integration;

use OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fakes\RecordingBootstrap;
use OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fakes\RecordingMessageService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fixtures\AppointmentFixtureManager;
use OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fixtures\PatientFixtureManager;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

abstract class IntegrationTestCase extends TestCase
{
    protected PatientFixtureManager $patients;
    protected AppointmentFixtureManager $appointments;
    private ?RecordingBootstrap $bootstrap = null;

    protected function setUp(): void
    {
        $this->patients = new PatientFixtureManager();
        $this->appointments = new AppointmentFixtureManager();

        // A prior run may have crashed mid-test, leaving fixture rows on
        // disk. Scrub everything before inserting fresh fixtures so this
        // run is deterministic. Order matters: module rows reference
        // patient ids, so they must go before the patients themselves.
        $this->purgeModuleRowsForFixturePatients();
        $this->appointments->removeFixtures();
        $this->patients->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeModuleRowsForFixturePatients();
        $this->appointments->removeFixtures();
        $this->patients->removeFixtures();
    }

    /**
     * Run the appointment reminder service with MessageService swapped for
     * the recording fake. Returns the result array from the service.
     *
     * @return array{sent: int, skipped: int, failed: int, errors: list<string>}
     */
    protected function runReminders(\DateTimeImmutable $now): array
    {
        $bootstrap = $this->getBootstrap();
        return $bootstrap->getAppointmentReminderService()->run($now);
    }

    protected function recorder(): RecordingMessageService
    {
        return $this->getBootstrap()->getRecorder();
    }

    private function getBootstrap(): RecordingBootstrap
    {
        if ($this->bootstrap === null) {
            $kernel = ($GLOBALS['kernel'] ?? null) instanceof Kernel
                ? $GLOBALS['kernel']
                : new Kernel();
            $this->bootstrap = new RecordingBootstrap(
                new EventDispatcher(),
                $kernel
            );
        }
        return $this->bootstrap;
    }

    /**
     * Delete oce_sinch_* rows that reference our fixture patients.
     *
     * Module tables are FK-free so order is just hygiene. We scope by
     * patient_id IN (fixture pids) so rows from real dev data are
     * untouched.
     */
    private function purgeModuleRowsForFixturePatients(): void
    {
        $pids = QueryUtils::fetchRecords(
            "SELECT pid FROM patient_data WHERE pubpid LIKE ?",
            [PatientFixtureManager::PUBPID_PREFIX . '%']
        );
        if ($pids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $ids = array_map(static fn(array $r): int => (int) $r['pid'], $pids);

        QueryUtils::sqlStatementThrowException(
            "DELETE FROM oce_sinch_appointment_reminders WHERE patient_id IN ({$placeholders})",
            $ids
        );
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM oce_sinch_messages WHERE conversation_id IN (
                SELECT conversation_id FROM oce_sinch_conversations WHERE patient_id IN ({$placeholders})
            )",
            $ids
        );
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM oce_sinch_conversations WHERE patient_id IN ({$placeholders})",
            $ids
        );
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM oce_sinch_contacts WHERE patient_id IN ({$placeholders})",
            $ids
        );
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM oce_sinch_patient_consent WHERE patient_id IN ({$placeholders})",
            $ids
        );
    }
}
