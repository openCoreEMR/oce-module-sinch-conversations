<?php

/**
 * Manages test patient rows in patient_data.
 *
 * Same prefix-tagged-cleanup convention as upstream BaseFixtureManager
 * (tools/openemr/.../tests/Tests/Fixtures/BaseFixtureManager.php), but
 * scoped to our module's prefix so a parallel run of upstream's own tests
 * cannot remove our fixtures or vice versa.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fixtures;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;

class PatientFixtureManager
{
    public const PUBPID_PREFIX = 'oce-sinch-test-fixture';

    /**
     * Insert a patient and return its pid.
     *
     * @param array<string, string|null> $overrides Column overrides. The
     *     fixture defaults are minimal but valid; tests typically override
     *     phone_cell and hipaa_allowsms to drive specific scenarios.
     */
    public function insert(array $overrides = []): int
    {
        $pubpid = self::PUBPID_PREFIX . '-' . bin2hex(random_bytes(4));
        $defaults = [
            'pubpid' => $pubpid,
            'fname' => self::PUBPID_PREFIX,
            'lname' => 'Patient-' . bin2hex(random_bytes(2)),
            'DOB' => '1980-01-01',
            'sex' => 'Female',
            'phone_cell' => '+15555550000',
            'hipaa_allowsms' => 'YES',
        ];
        $values = array_merge($defaults, $overrides);

        $nextPid = $this->getNextPid();
        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();

        $columns = array_merge($values, ['pid' => $nextPid, 'uuid' => $uuid]);
        $setClauses = [];
        $binds = [];
        foreach ($columns as $col => $val) {
            $setClauses[] = "`{$col}` = ?";
            $binds[] = $val;
        }
        $sql = "INSERT INTO patient_data SET " . implode(', ', $setClauses);
        QueryUtils::sqlStatementThrowException($sql, $binds);

        return $nextPid;
    }

    public function removeFixtures(): void
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `uuid` FROM patient_data WHERE pubpid LIKE ?",
            [self::PUBPID_PREFIX . '%']
        );
        foreach ($rows as $row) {
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM uuid_registry WHERE table_name = 'patient_data' AND `uuid` = ?",
                [$row['uuid']]
            );
        }
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM patient_data WHERE pubpid LIKE ?",
            [self::PUBPID_PREFIX . '%']
        );
    }

    private function getNextPid(): int
    {
        $row = QueryUtils::querySingleRow("SELECT IFNULL(MAX(pid), 0) + 1 AS next_pid FROM patient_data");
        return (int) ($row['next_pid'] ?? 1);
    }
}
