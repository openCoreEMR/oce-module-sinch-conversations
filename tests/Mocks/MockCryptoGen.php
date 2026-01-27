<?php

/**
 * Mock CryptoGen for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Crypto;

/**
 * Mock CryptoGen to avoid crypto operations during tests
 *
 * This mock simply uses base64 encoding/decoding to simulate encryption.
 */
class CryptoGen
{
    public function __construct()
    {
    }

    /**
     * Mock encryption - uses base64 encoding
     */
    public function encryptStandard(string $value): string
    {
        return base64_encode($value);
    }

    /**
     * Mock decryption - uses base64 decoding
     *
     * @return string|false
     */
    public function decryptStandard(string $value): string|false
    {
        return base64_decode($value);
    }
}
