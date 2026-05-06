<?php

/**
 * Unit tests for ConsentBlock
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\ConsentBlock;
use OpenCoreEMR\Modules\SinchConversations\SkipReason;
use PHPUnit\Framework\TestCase;

class ConsentBlockTest extends TestCase
{
    public function testEvaluateReturnsNullForMissingRow(): void
    {
        $this->assertNull(ConsentBlock::evaluate(null));
    }

    public function testEvaluateReturnsNullWhenNoBlockingFlagSet(): void
    {
        // Patient who opted in via module flow but has no block on file:
        // chart YES is authoritative, so the helper should yield no block.
        $this->assertNull(ConsentBlock::evaluate([
            'opted_in' => true,
            'opted_out' => false,
            'carrier_blocked' => false,
            'carrier_block_reason' => null,
        ]));
    }

    public function testEvaluateReturnsNullForRowWithAllFlagsFalse(): void
    {
        // Sparse row left behind by a partial flow (e.g., a contact created
        // for an inbound STOP that has since been overwritten). With every
        // blocking flag FALSE, chart YES is authoritative.
        $this->assertNull(ConsentBlock::evaluate([
            'opted_in' => false,
            'opted_out' => false,
            'carrier_blocked' => false,
        ]));
    }

    public function testEvaluateReturnsCarrierBlockedWithReasonContext(): void
    {
        $block = ConsentBlock::evaluate([
            'opted_in' => false,
            'opted_out' => false,
            'carrier_blocked' => true,
            'carrier_block_reason' => 'smpp_255',
        ]);

        $this->assertNotNull($block);
        $this->assertSame(SkipReason::CarrierBlocked, $block->reason);
        $this->assertSame(['carrier_block_reason' => 'smpp_255'], $block->context);
    }

    public function testEvaluateReturnsCarrierBlockedWithNullReasonWhenColumnMissing(): void
    {
        // The carrier_block_reason column is nullable; a block recorded
        // without a reason should still produce a CarrierBlocked verdict
        // with a null context value (not absent / not the string "null").
        $block = ConsentBlock::evaluate([
            'opted_in' => false,
            'opted_out' => false,
            'carrier_blocked' => true,
        ]);

        $this->assertNotNull($block);
        $this->assertSame(SkipReason::CarrierBlocked, $block->reason);
        $this->assertSame(['carrier_block_reason' => null], $block->context);
    }

    public function testEvaluateReturnsModuleOptOutForOptedOutRow(): void
    {
        $block = ConsentBlock::evaluate([
            'opted_in' => true,
            'opted_out' => true,
            'carrier_blocked' => false,
        ]);

        $this->assertNotNull($block);
        $this->assertSame(SkipReason::ModuleOptOut, $block->reason);
        $this->assertSame(['consent_state' => 'opted_out'], $block->context);
    }

    public function testEvaluatePrefersCarrierBlockedWhenBothFlagsSet(): void
    {
        // Steady state of the carrier-block flow: setCarrierBlock() then
        // optOut() leaves both flags TRUE. The helper must report the more
        // specific carrier-block reason (with carrier_block_reason context),
        // not collapse it to module_opt_out.
        $block = ConsentBlock::evaluate([
            'opted_in' => false,
            'opted_out' => true,
            'carrier_blocked' => true,
            'carrier_block_reason' => 'smpp_255',
        ]);

        $this->assertNotNull($block);
        $this->assertSame(SkipReason::CarrierBlocked, $block->reason);
        $this->assertSame(['carrier_block_reason' => 'smpp_255'], $block->context);
    }
}
