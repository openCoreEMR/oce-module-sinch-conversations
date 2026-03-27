<?php

/**
 * Unit tests for PhoneNormalizer
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\Service\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /**
     * @dataProvider validPhoneProvider
     */
    public function testNormalizesToE164(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::toE164($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validPhoneProvider(): array
    {
        return [
            'already E.164' => ['+15551234567', '+15551234567'],
            '10-digit US' => ['5551234567', '+15551234567'],
            '10-digit with dashes' => ['555-123-4567', '+15551234567'],
            '10-digit with parens' => ['(555) 123-4567', '+15551234567'],
            '10-digit with spaces' => ['555 123 4567', '+15551234567'],
            '10-digit with dots' => ['555.123.4567', '+15551234567'],
            '11-digit with country code' => ['15551234567', '+15551234567'],
            '11-digit with leading 1 and dashes' => ['1-555-123-4567', '+15551234567'],
            'E.164 with extra spaces' => ['+1 555 123 4567', '+15551234567'],
            'international UK' => ['+442071234567', '+442071234567'],
            'leading whitespace' => ['  +15551234567', '+15551234567'],
            'trailing whitespace' => ['+15551234567  ', '+15551234567'],
            'leading and trailing whitespace' => ['  5551234567  ', '+15551234567'],
        ];
    }

    /**
     * @dataProvider invalidPhoneProvider
     */
    public function testReturnsNullForInvalidInput(string $input): void
    {
        $this->assertNull(PhoneNormalizer::toE164($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPhoneProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'letters only' => ['abcdefg'],
            'too short' => ['12345'],
            'plus only' => ['+'],
            'ambiguous 9 digits no plus' => ['123456789'],
            'ambiguous 12 digits no plus' => ['441234567890'],
            'exceeds E.164 max of 15 digits' => ['+1234567890123456'],
        ];
    }

    public function testNormalizesConsistentlyRegardlessOfFormat(): void
    {
        $formats = [
            '+15551234567',
            '5551234567',
            '555-123-4567',
            '(555) 123-4567',
            '1-555-123-4567',
            '15551234567',
            '+1 555 123 4567',
            '555.123.4567',
        ];

        $results = array_map(PhoneNormalizer::toE164(...), $formats);
        $unique = array_unique($results);

        $this->assertCount(1, $unique, 'All formats should normalize to the same E.164 number');
        $this->assertSame('+15551234567', $unique[0]);
    }
}
