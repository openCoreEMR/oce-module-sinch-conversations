<?php

/**
 * Patient consent state for a single phone number
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
 * Stable consent classification used for gating sends and tagging logs.
 *
 * Values are written into log context (`consent_state` field) and are part
 * of the observability contract — keep them stable across releases.
 */
enum ConsentState: string
{
    case Active = 'active';
    case None = 'none';
    case OptedOut = 'opted_out';
    case NotOptedIn = 'not_opted_in';

    /**
     * Classify a row from oce_sinch_patient_consent.
     *
     * Treats null and empty array the same so call sites that use
     * `QueryUtils::querySingleRow()` (returns null on no row) and call sites
     * that pass `[]` for "row not found" both map to None.
     *
     * @param array<string, mixed>|null $row
     */
    public static function fromRow(?array $row): self
    {
        if ($row === null || $row === []) {
            return self::None;
        }
        if ((bool) ($row['opted_out'] ?? false)) {
            return self::OptedOut;
        }
        if (!(bool) ($row['opted_in'] ?? false)) {
            return self::NotOptedIn;
        }
        return self::Active;
    }
}
