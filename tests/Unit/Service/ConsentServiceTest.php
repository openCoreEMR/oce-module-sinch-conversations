<?php

/**
 * Unit tests for ConsentService
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
use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsentServiceTest extends TestCase
{
    private GlobalConfig $config;
    private TemplateService&MockObject $templateService;
    private MessageService&MockObject $messageService;
    private ConsentService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->config = new GlobalConfig(new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
        ]));
        $this->templateService = $this->createMock(TemplateService::class);
        $this->messageService = $this->createMock(MessageService::class);

        $this->service = new ConsentService(
            $this->config,
            $this->templateService,
            $this->messageService
        );
    }

    // --- hasConsent ---

    public function testHasConsentReturnsTrueWhenOptedIn(): void
    {
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            [['opted_in' => true, 'opted_out' => false]]
        );

        $this->assertTrue($this->service->hasConsent(1, '+15551234567'));
    }

    public function testHasConsentReturnsFalseWhenOptedOut(): void
    {
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            [['opted_in' => true, 'opted_out' => true]]
        );

        $this->assertFalse($this->service->hasConsent(1, '+15551234567'));
    }

    public function testHasConsentReturnsFalseWhenNoRecord(): void
    {
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            []
        );

        $this->assertFalse($this->service->hasConsent(1, '+15551234567'));
    }

    public function testHasConsentReturnsFalseWhenNotOptedIn(): void
    {
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            [['opted_in' => false, 'opted_out' => false]]
        );

        $this->assertFalse($this->service->hasConsent(1, '+15551234567'));
    }

    // --- optIn ---

    public function testOptInInsertsConsentAndSendsConfirmation(): void
    {
        $this->templateService->method('render')
            ->with('opt_in_confirmation', $this->anything())
            ->willReturn('Welcome!');

        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->with(1, '+15551234567', 'Welcome!', new MessageOptions(
                templateKey: 'opt_in_confirmation',
                skipConsentCheck: true,
            ));

        $result = $this->service->optIn(1, '+15551234567', 'web_form', '192.168.1.1');

        $this->assertTrue($result);
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent'));
        $this->assertNotEmpty($insertQueries);

        // Verify hipaa_allowsms is synced to YES
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertNotEmpty($hipaaQueries);
        $hipaaUpdate = array_values($hipaaQueries)[0];
        $this->assertEquals('YES', $hipaaUpdate['binds'][0]);
        $this->assertEquals(1, $hipaaUpdate['binds'][1]);
    }

    public function testOptInContinuesWhenConfirmationFails(): void
    {
        $this->templateService->method('render')->willReturn('Welcome!');
        $this->messageService->method('sendToPatient')
            ->willThrowException(new \RuntimeException('API error'));

        // Should not throw — opt-in succeeds but returns false to indicate confirmation failed
        $result = $this->service->optIn(1, '+15551234567', 'web_form');

        $this->assertFalse($result);

        // Consent record is still persisted even when confirmation fails
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent'));
        $this->assertNotEmpty($insertQueries);

        $logs = SystemLogger::getLogs();
        $errorLogs = array_filter($logs, fn($log) => $log['level'] === 'error');
        $this->assertNotEmpty($errorLogs);
    }

    // --- optOut ---

    public function testOptOutUpdatesConsent(): void
    {
        $this->service->optOut(1, '+15551234567', 'sms_stop');

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_patient_consent'));
        $this->assertNotEmpty($updateQueries);

        // Verify the method param is first bind
        $update = array_values($updateQueries)[0];
        $this->assertEquals('sms_stop', $update['binds'][0]);

        // Verify hipaa_allowsms is synced to NO
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertNotEmpty($hipaaQueries);
        $hipaaUpdate = array_values($hipaaQueries)[0];
        $this->assertEquals('NO', $hipaaUpdate['binds'][0]);
        $this->assertEquals(1, $hipaaUpdate['binds'][1]);
    }

    // --- getConsent ---

    public function testGetConsentReturnsRecord(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            [['patient_id' => 1, 'phone_number' => '+15551234567', 'opted_in' => true]]
        );

        $result = $this->service->getConsent(1, '+15551234567');

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['patient_id']);
    }

    public function testGetConsentReturnsNullWhenNotFound(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            []
        );

        $this->assertNull($this->service->getConsent(1, '+15551234567'));
    }
}
