<?php

/**
 * Unit tests for ConsentState
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\ConsentState;
use PHPUnit\Framework\TestCase;

class ConsentStateTest extends TestCase
{
    public function testFromRowMapsNullToNone(): void
    {
        $this->assertSame(ConsentState::None, ConsentState::fromRow(null));
    }

    public function testFromRowMapsEmptyArrayToNone(): void
    {
        $this->assertSame(ConsentState::None, ConsentState::fromRow([]));
    }

    public function testFromRowMapsOptedOutEvenWhenAlsoOptedIn(): void
    {
        // opted_out wins over opted_in: a patient who opted in and later
        // opted out is OptedOut, not Active.
        $this->assertSame(
            ConsentState::OptedOut,
            ConsentState::fromRow(['opted_in' => true, 'opted_out' => true])
        );
    }

    public function testFromRowMapsRowWithoutOptInFlagToNotOptedIn(): void
    {
        $this->assertSame(
            ConsentState::NotOptedIn,
            ConsentState::fromRow(['opted_in' => false, 'opted_out' => false])
        );
    }

    public function testFromRowMapsActiveConsent(): void
    {
        $this->assertSame(
            ConsentState::Active,
            ConsentState::fromRow(['opted_in' => true, 'opted_out' => false])
        );
    }

    public function testValuesAreStableObservabilityContract(): void
    {
        // These string values are written to log context. Changing them
        // breaks dashboards and alerting; the test guards against that.
        $this->assertSame('active', ConsentState::Active->value);
        $this->assertSame('none', ConsentState::None->value);
        $this->assertSame('opted_out', ConsentState::OptedOut->value);
        $this->assertSame('not_opted_in', ConsentState::NotOptedIn->value);
    }
}
