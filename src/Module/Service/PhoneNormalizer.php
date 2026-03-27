<?php

/**
 * Phone number normalization to E.164 format
 *
 * Normalize all phone numbers at the boundary (reading from patient_data,
 * receiving from Sinch) so all internal comparisons use exact E.164 strings.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Service;

class PhoneNormalizer
{
    /**
     * Default country code when the number has no international prefix.
     * US/Canada = 1.
     */
    private const DEFAULT_COUNTRY_CODE = '1';

    /**
     * Normalize a phone number to E.164 format (+<country><number>).
     *
     * Strip all non-digit characters, prepend country code if missing,
     * then add the + prefix.
     *
     * Return null if the input is empty or too short to be a valid number.
     */
    public static function toE164(string $phone): ?string
    {
        // Strip everything except digits
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            // Already has country code prefix
            $normalized = '+' . $digits;
        } elseif (strlen($digits) === 10) {
            // 10-digit US/CA number without country code
            $normalized = '+' . self::DEFAULT_COUNTRY_CODE . $digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, self::DEFAULT_COUNTRY_CODE)) {
            // 11-digit number already starting with country code (e.g. 15551234567)
            $normalized = '+' . $digits;
        } else {
            // Ambiguous digit count without a '+' prefix -- we cannot
            // distinguish a mistyped US number from an international one.
            // Return null so callers can surface the error rather than
            // silently creating a bogus E.164 number.
            return null;
        }

        // Minimum E.164 length is + plus at least 7 digits
        if (strlen($normalized) < 8) {
            return null;
        }

        return $normalized;
    }
}
