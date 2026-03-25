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
        $this->mockPatientLookup('+15559999999', ['pid' => 42, 'fname' => 'John', 'lname' => 'Doe']);

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

    // --- START keywords ---

    /**
     * @dataProvider startKeywordProvider
     */
    public function testStartKeywordsOptInAndRespond(string $keyword): void
    {
        $this->mockPatientLookup('+15559999999', ['pid' => 42, 'fname' => 'John', 'lname' => 'Doe']);

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

    // --- HELP keywords ---

    /**
     * @dataProvider helpKeywordProvider
     */
    public function testHelpKeywordsRespond(string $keyword): void
    {
        // HELP doesn't need a patient lookup for the response, but the code
        // checks for the patient first
        $this->mockPatientLookup('+15559999999', ['pid' => 42, 'fname' => 'Jane', 'lname' => 'Doe']);

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
        $this->mockPatientLookup('+15559999999', null);

        $result = $this->service->handleInboundMessage('+15559999999', 'STOP');

        $this->assertNull($result);
    }

    public function testLogsWarningForUnknownPatient(): void
    {
        $this->mockPatientLookup('+15559999999', null);

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
        $this->mockPatientLookup('+15559999999', ['pid' => 1, 'fname' => 'A', 'lname' => 'B']);

        $this->templateService->method('render')->willReturn('response');

        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', 'Stop'));
        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', 'HELP'));
        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', 'start'));
    }

    public function testWhitespaceIsTrimmed(): void
    {
        $this->mockPatientLookup('+15559999999', ['pid' => 1, 'fname' => 'A', 'lname' => 'B']);
        $this->templateService->method('render')->willReturn('response');

        $this->assertNotNull($this->service->handleInboundMessage('+15559999999', '  STOP  '));
    }

    // --- Helpers ---

    /**
     * @param array<string, mixed>|null $patient
     */
    private function mockPatientLookup(string $phoneNumber, ?array $patient): void
    {
        $normalized = preg_replace('/[^0-9]/', '', $phoneNumber);

        QueryUtils::setMockResult(
            "SELECT pid, fname, lname, phone_cell
                FROM patient_data
                WHERE REPLACE(REPLACE(REPLACE(phone_cell, '-', ''), ' ', ''), '+', '') LIKE ?
                LIMIT 1",
            ['%' . $normalized],
            $patient ? [$patient] : []
        );
    }
}
