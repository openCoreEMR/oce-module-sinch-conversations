<?php

/**
 * Unit tests for KeywordHandlerService
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\Service\KeywordHandlerService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class KeywordHandlerServiceTest extends TestCase
{
    private GlobalConfig $config;
    private ConsentService&MockObject $consentService;
    private TemplateService&MockObject $templateService;
    private KeywordHandlerService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
        ]);
        $this->config = new GlobalConfig($mockGlobals);
        $this->consentService = $this->createMock(ConsentService::class);
        $this->templateService = $this->createMock(TemplateService::class);

        $this->service = new KeywordHandlerService(
            $this->config,
            $this->consentService,
            $this->templateService
        );
    }

    // --- Non-keyword messages ---

    public function testReturnsNullForRegularMessage(): void
    {
        $this->assertNull($this->service->handleInboundMessage('+15559999999', 'Hello doctor'));
    }

    public function testReturnsNullForEmptyMessage(): void
    {
        $this->assertNull($this->service->handleInboundMessage('+15559999999', ''));
    }

    public function testReturnsNullForPartialKeyword(): void
    {
        $this->assertNull($this->service->handleInboundMessage('+15559999999', 'STOP NOW'));
    }

    // --- STOP keywords ---

    /**
     * @dataProvider stopKeywordProvider
     */
    public function testStopKeywordsOptOutAndRespond(string $keyword): void
    {
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 42, 'fname' => 'John', 'lname' => 'Doe', 'phone_cell' => '555-999-9999'],
        ]);

        $this->consentService->expects($this->once())
            ->method('optOut')
            ->with(42, '+15559999999', 'sms_stop');

        $this->templateService->expects($this->once())
            ->method('render')
            ->with('keyword_stop', [
                'clinic_name' => 'Test Clinic',
                'phone' => '+15551234567',
            ])
            ->willReturn('You have been unsubscribed.');

        $result = $this->service->handleInboundMessage('+15559999999', $keyword);

        $this->assertEquals('You have been unsubscribed.', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stopKeywordProvider(): array
    {
        return [
            'STOP' => ['STOP'],
            'stop lowercase' => ['stop'],
            'STOPALL' => ['STOPALL'],
            'UNSUBSCRIBE' => ['UNSUBSCRIBE'],
            'CANCEL' => ['CANCEL'],
            'END' => ['END'],
            'QUIT' => ['QUIT'],
        ];
    }

    // --- STOP with multiple patients (issue #39) ---

    public function testStopOptsOutAllPatientsSharingPhoneNumber(): void
    {
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 10, 'fname' => 'Parent', 'lname' => 'Smith', 'phone_cell' => '555-999-9999'],
            ['pid' => 11, 'fname' => 'Child1', 'lname' => 'Smith', 'phone_cell' => '555-999-9999'],
            ['pid' => 12, 'fname' => 'Child2', 'lname' => 'Smith', 'phone_cell' => '555-999-9999'],
        ]);

        $this->consentService->expects($this->exactly(3))
            ->method('optOut')
            ->willReturnCallback(function (int $pid, string $phone, string $method): void {
                $this->assertSame('+15559999999', $phone);
                $this->assertSame('sms_stop', $method);
                $this->assertContains($pid, [10, 11, 12]);
            });

        $this->templateService->method('render')->willReturn('Unsubscribed.');

        $result = $this->service->handleInboundMessage('+15559999999', 'STOP');

        $this->assertSame('Unsubscribed.', $result);
    }

    // --- START keywords ---

    /**
     * @dataProvider startKeywordProvider
     */
    public function testStartKeywordsOptInAndRespond(string $keyword): void
    {
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 42, 'fname' => 'John', 'lname' => 'Doe', 'phone_cell' => '555-999-9999'],
        ]);

        $this->consentService->expects($this->once())
            ->method('optIn')
            ->with(42, '+15559999999', 'sms_start', null);

        $this->templateService->expects($this->once())
            ->method('render')
            ->with('keyword_start', ['clinic_name' => 'Test Clinic'])
            ->willReturn('You have been re-subscribed.');

        $result = $this->service->handleInboundMessage('+15559999999', $keyword);

        $this->assertEquals('You have been re-subscribed.', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function startKeywordProvider(): array
    {
        return [
            'START' => ['START'],
            'start lowercase' => ['start'],
            'UNSTOP' => ['UNSTOP'],
            'SUBSCRIBE' => ['SUBSCRIBE'],
        ];
    }

    public function testStartOnlyOptsInFirstPatientWhenMultipleShareNumber(): void
    {
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 10, 'fname' => 'Parent', 'lname' => 'Smith', 'phone_cell' => '555-999-9999'],
            ['pid' => 11, 'fname' => 'Child1', 'lname' => 'Smith', 'phone_cell' => '555-999-9999'],
        ]);

        $this->consentService->expects($this->once())
            ->method('optIn')
            ->with(10, '+15559999999', 'sms_start', null);

        $this->templateService->method('render')->willReturn('Re-subscribed.');

        $result = $this->service->handleInboundMessage('+15559999999', 'START');

        $this->assertSame('Re-subscribed.', $result);
    }

    // --- HELP keywords ---

    /**
     * @dataProvider helpKeywordProvider
     */
    public function testHelpKeywordsRespond(string $keyword): void
    {
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 42, 'fname' => 'Jane', 'lname' => 'Doe', 'phone_cell' => '555-999-9999'],
        ]);

        $this->templateService->expects($this->once())
            ->method('render')
            ->with('keyword_help', [
                'clinic_name' => 'Test Clinic',
                'phone' => '+15551234567',
            ])
            ->willReturn('For help, call us.');

        $result = $this->service->handleInboundMessage('+15559999999', $keyword);

        $this->assertEquals('For help, call us.', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function helpKeywordProvider(): array
    {
        return [
            'HELP' => ['HELP'],
            'help lowercase' => ['help'],
            'INFO' => ['INFO'],
        ];
    }

    // --- Unknown patient ---

    public function testReturnsNullForUnknownPatient(): void
    {
        $this->mockPatientLookup('+15559999999', []);

        $result = $this->service->handleInboundMessage('+15559999999', 'STOP');

        $this->assertNull($result);
    }

    public function testLogsWarningForUnknownPatient(): void
    {
        $this->mockPatientLookup('+15559999999', []);

        $this->service->handleInboundMessage('+15559999999', 'STOP');

        $logs = SystemLogger::getLogs();
        $found = false;
        foreach ($logs as $log) {
            if ($log['level'] === 'warning' && str_contains($log['message'], 'unknown number')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected warning log for unknown patient');
    }

    // --- Case insensitivity ---

    public function testKeywordsAreCaseInsensitive(): void
    {
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 1, 'fname' => 'A', 'lname' => 'B', 'phone_cell' => '555-999-9999'],
        ]);

        $this->templateService->method('render')->willReturn('response');

        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', 'Stop'));
        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', 'HELP'));
        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', 'start'));
    }

    public function testWhitespaceIsTrimmed(): void
    {
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 1, 'fname' => 'A', 'lname' => 'B', 'phone_cell' => '555-999-9999'],
        ]);
        $this->templateService->method('render')->willReturn('response');

        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', '  STOP  '));
    }

    // --- Phone normalization (issue #43) ---

    public function testNormalizesNonE164PhoneBeforeLookup(): void
    {
        // Input is a raw US number, not E.164
        $this->mockPatientLookup('+15559999999', [
            ['pid' => 1, 'fname' => 'A', 'lname' => 'B', 'phone_cell' => '555-999-9999'],
        ]);
        $this->templateService->method('render')->willReturn('response');

        // Pass raw format -- should still find the patient via normalization
        $this->assertNotNull($this->service->handleInboundMessage('555-999-9999', 'STOP'));
    }

    public function testReturnsNullForUnparseablePhone(): void
    {
        $result = $this->service->handleInboundMessage('abc', 'STOP');
        $this->assertNull($result);
    }

    // --- Helpers ---

    /**
     * Mock the fetchRecords call used by findPatientsByPhone.
     * The query matches on the last 10 digits of the phone number.
     *
     * @param string $phoneNumber E.164 number
     * @param list<array<string, mixed>> $patients
     */
    private function mockPatientLookup(string $phoneNumber, array $patients): void
    {
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);
        $national = substr($digits, -10);

        QueryUtils::setMockResult(
            "SELECT pid, fname, lname, phone_cell
                FROM patient_data
                WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_cell, '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), 10) = ?
                AND phone_cell IS NOT NULL
                AND phone_cell != ''
                ORDER BY pid ASC",
            [$national],
            $patients
        );
    }
}
