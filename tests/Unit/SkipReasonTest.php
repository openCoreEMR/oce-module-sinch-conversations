<?php

/**
 * Unit tests for SkipReason
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\SkipReason;
use PHPUnit\Framework\TestCase;

class SkipReasonTest extends TestCase
{
    public function testValuesAreStableObservabilityContract(): void
    {
        // These string values are written to the `reason` field of WARNING
        // logs and consumed by alerting/dashboards. Renaming them is a
        // breaking change.
        $this->assertSame('missing_phone', SkipReason::MissingPhone->value);
        $this->assertSame('unparseable_phone', SkipReason::UnparseablePhone->value);
        $this->assertSame('hipaa_disallows_sms', SkipReason::HipaaDisallowsSms->value);
        $this->assertSame('no_active_consent', SkipReason::NoActiveConsent->value);
    }
}
