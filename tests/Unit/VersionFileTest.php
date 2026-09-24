<?php

/**
 * Unit tests for version.php
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc.
 * @link      https://www.opencoreemr.com
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use PHPUnit\Framework\TestCase;

class VersionFileTest extends TestCase
{
    /** See the header comment in version.php for why this must hold. */
    public function testIncludesTwiceInOneProcess(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });
        try {
            $first = self::loadVersion();
            $second = self::loadVersion();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame($first, $second);
    }

    /**
     * @return array{mixed, mixed, mixed, mixed, mixed}
     */
    private static function loadVersion(): array
    {
        include __DIR__ . '/../../version.php';
        return [$v_major, $v_minor, $v_patch, $v_tag, $v_database];
    }
}
