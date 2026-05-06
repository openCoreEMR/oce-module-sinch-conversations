<?php

/**
 * Module-side block on a chart YES opt-in
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

/**
 * A module-side block that overrides the chart's hipaa_allowsms='YES' opt-in.
 *
 * Not a PHP exception — does not extend \Throwable. Represents a domain-level
 * "exception to the chart YES rule": a row from oce_sinch_patient_consent
 * that the chart's positive consent cannot override (an explicit STOP keyword
 * opt-out, a channel-native opt-out webhook, or a carrier block discovered
 * via SMPP error codes or Sinch consent-API reconciliation).
 *
 * Centralizing the row → block mapping here keeps the carrier-blocked
 * vs. opted-out check ordering in one place. The send gate, the diagnostic
 * verdict surface, the appointment-reminder cron, and the welcome-SMS
 * listener all share this evaluation; drifting any one of them silently
 * would mask a carrier_block_reason or change the gating semantics in only
 * some paths.
 */
final readonly class ConsentBlock
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        public SkipReason $reason,
        public array $context,
    ) {
    }

    /**
     * Evaluate a row from oce_sinch_patient_consent.
     *
     * Returns null when no module-side block applies — either no row exists,
     * or the row has no blocking flag set. Callers that see null should
     * treat the chart's hipaa_allowsms='YES' as authoritative and proceed.
     *
     * Check ordering: carrier_blocked before opted_out. The normal carrier-
     * block flow (setCarrierBlock then optOut) leaves both flags TRUE; the
     * carrier block is the more specific cause, and reporting opt-out first
     * would mask the carrier_block_reason context for every steady-state
     * carrier-block row.
     *
     * @param array<string, mixed>|null $row
     */
    public static function evaluate(?array $row): ?self
    {
        if ($row === null) {
            return null;
        }

        if ((bool) ($row['carrier_blocked'] ?? false)) {
            $blockReason = is_string($row['carrier_block_reason'] ?? null)
                ? $row['carrier_block_reason']
                : null;
            return new self(
                reason: SkipReason::CarrierBlocked,
                context: ['carrier_block_reason' => $blockReason],
            );
        }

        if (ConsentState::fromRow($row) === ConsentState::OptedOut) {
            return new self(
                reason: SkipReason::ModuleOptOut,
                context: ['consent_state' => ConsentState::OptedOut->value],
            );
        }

        return null;
    }
}
