<?php

/**
 * Unit tests for ConsentSyncService
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
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentSyncService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsentSyncServiceTest extends TestCase
{
    private GlobalConfig $config;
    private ConversationApiClient&MockObject $apiClient;
    private ConsentService&MockObject $consentService;
    private ConsentSyncService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->config = new GlobalConfig(new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_APP_ID => 'test-app-id',
        ]), new MockConfigFactory());

        $this->apiClient = $this->createMock(ConversationApiClient::class);
        $this->consentService = $this->createMock(ConsentService::class);

        $this->service = new ConsentSyncService(
            $this->config,
            $this->apiClient,
            $this->consentService
        );
    }

    public function testSyncOptOutsProcessesIdentities(): void
    {
        $this->apiClient->method('listOptOuts')
            ->with('test-app-id')
            ->willReturn([
                ['identity' => '15551234567'],
                ['identity' => '15559876543'],
            ]);

        // Patient lookup for first number
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?",
            ['+15551234567'],
            [['patient_id' => 1]]
        );
        // Patient lookup for second number
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?",
            ['+15559876543'],
            [['patient_id' => 2]]
        );
        // No local blocks to clear
        $this->mockClearLiftedBlocksQuery([]);

        $this->consentService->method('getCarrierBlock')->willReturn(null);

        $this->consentService->expects($this->exactly(2))
            ->method('setCarrierBlock');

        $this->consentService->expects($this->exactly(2))
            ->method('optOut');

        $result = $this->service->syncOptOuts();

        $this->assertEquals(2, $result['blocked']);
        $this->assertEquals(0, $result['cleared']);
        $this->assertEquals(0, $result['already_blocked']);
        $this->assertEquals(0, $result['errors']);
    }

    public function testSyncOptOutsSkipsAlreadyBlocked(): void
    {
        $this->apiClient->method('listOptOuts')
            ->willReturn([
                ['identity' => '15551234567'],
            ]);

        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?",
            ['+15551234567'],
            [['patient_id' => 1]]
        );
        $this->mockClearLiftedBlocksQuery([]);

        $this->consentService->method('getCarrierBlock')
            ->willReturn(['carrier_blocked_at' => '2026-04-01', 'carrier_block_reason' => 'SMPP error 255']);

        $this->consentService->expects($this->never())->method('setCarrierBlock');
        $this->consentService->expects($this->never())->method('optOut');

        $result = $this->service->syncOptOuts();

        $this->assertEquals(0, $result['blocked']);
        $this->assertEquals(1, $result['already_blocked']);
        $this->assertEquals(0, $result['errors']);
    }

    public function testSyncOptOutsHandlesEmptyList(): void
    {
        $this->apiClient->method('listOptOuts')->willReturn([]);
        $this->mockClearLiftedBlocksQuery([]);

        $this->consentService->expects($this->never())->method('setCarrierBlock');

        $result = $this->service->syncOptOuts();

        $this->assertEquals(0, $result['blocked']);
        $this->assertEquals(0, $result['already_blocked']);
        $this->assertEquals(0, $result['errors']);
    }

    public function testSyncOptOutsCountsErrors(): void
    {
        $this->apiClient->method('listOptOuts')
            ->willReturn([
                ['identity' => '15551234567'],
            ]);

        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?",
            ['+15551234567'],
            [['patient_id' => 1]]
        );
        $this->mockClearLiftedBlocksQuery([]);

        $this->consentService->method('getCarrierBlock')->willReturn(null);
        $this->consentService->method('setCarrierBlock')
            ->willThrowException(new \RuntimeException('DB error'));

        $result = $this->service->syncOptOuts();

        $this->assertEquals(0, $result['blocked']);
        $this->assertEquals(1, $result['errors']);
    }

    public function testSyncOptOutsSkipsNoPatientsFound(): void
    {
        $this->apiClient->method('listOptOuts')
            ->willReturn([
                ['identity' => '15551234567'],
            ]);

        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?",
            ['+15551234567'],
            []
        );
        $this->mockClearLiftedBlocksQuery([]);

        $this->consentService->expects($this->never())->method('setCarrierBlock');

        $result = $this->service->syncOptOuts();

        $this->assertEquals(0, $result['blocked']);
    }

    public function testCheckIdentityReturnsConsentData(): void
    {
        $this->apiClient->method('getConsentStatus')
            ->with('test-app-id', '+15551234567')
            ->willReturn(['identity' => '15551234567', 'status' => 'OPT_OUT']);

        $result = $this->service->checkIdentity('+15551234567');

        $this->assertNotEmpty($result);
        $this->assertEquals('OPT_OUT', $result['status']);
    }

    public function testCheckIdentityReturnsEmptyWhenNotFound(): void
    {
        $this->apiClient->method('getConsentStatus')
            ->with('test-app-id', '+15551234567')
            ->willReturn([]);

        $result = $this->service->checkIdentity('+15551234567');

        $this->assertEmpty($result);
    }

    public function testCheckIdentityReturnsEmptyForUnparseablePhone(): void
    {
        $this->apiClient->expects($this->never())->method('getConsentStatus');

        $result = $this->service->checkIdentity('not-a-phone');

        $this->assertEmpty($result);
    }

    public function testSyncOptOutsClearsLiftedBlocks(): void
    {
        // No opt-outs in Sinch
        $this->apiClient->method('listOptOuts')->willReturn([]);

        // But we have a local consent_api_sync block that should be cleared
        $this->mockClearLiftedBlocksQuery([
            ['patient_id' => 1, 'phone_number' => '+15551234567'],
        ]);

        $this->consentService->expects($this->once())
            ->method('clearCarrierBlock')
            ->with(1, '+15551234567');

        $result = $this->service->syncOptOuts();

        $this->assertEquals(0, $result['blocked']);
        $this->assertEquals(1, $result['cleared']);
    }

    public function testSyncOptOutsDoesNotClearBlockStillInSinch(): void
    {
        $this->apiClient->method('listOptOuts')
            ->willReturn([
                ['identity' => '15551234567'],
            ]);

        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?",
            ['+15551234567'],
            [['patient_id' => 1]]
        );

        // Patient is already blocked — won't be re-blocked
        $this->consentService->method('getCarrierBlock')
            ->willReturn(['carrier_blocked_at' => '2026-04-01', 'carrier_block_reason' => 'consent_api_sync']);

        // The local block still appears in Sinch, so it should NOT be cleared
        $this->mockClearLiftedBlocksQuery([
            ['patient_id' => 1, 'phone_number' => '+15551234567'],
        ]);

        $this->consentService->expects($this->never())->method('clearCarrierBlock');

        $result = $this->service->syncOptOuts();

        $this->assertEquals(0, $result['cleared']);
        $this->assertEquals(1, $result['already_blocked']);
    }

    public function testSyncOptOutsThrowsWhenListOptOutsFails(): void
    {
        $this->apiClient->method('listOptOuts')
            ->willThrowException(new \RuntimeException('API error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error');

        $this->service->syncOptOuts();
    }

    public function testSyncOptOutsHandlesMultiplePatientsPerPhone(): void
    {
        $this->apiClient->method('listOptOuts')
            ->willReturn([
                ['identity' => '15551234567'],
            ]);

        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_patient_consent WHERE phone_number = ?",
            ['+15551234567'],
            [['patient_id' => 1], ['patient_id' => 2], ['patient_id' => 3]]
        );
        $this->mockClearLiftedBlocksQuery([]);

        $this->consentService->method('getCarrierBlock')->willReturn(null);

        $this->consentService->expects($this->exactly(3))
            ->method('setCarrierBlock');

        $this->consentService->expects($this->exactly(3))
            ->method('optOut');

        $result = $this->service->syncOptOuts();

        $this->assertEquals(3, $result['blocked']);
    }

    // --- Helpers ---

    /**
     * Mock the query used by clearLiftedBlocks to find local consent_api_sync blocks
     *
     * @param list<array{patient_id: int, phone_number: string}> $rows
     */
    private function mockClearLiftedBlocksQuery(array $rows): void
    {
        QueryUtils::setMockResult(
            "SELECT patient_id, phone_number
                FROM oce_sinch_patient_consent
                WHERE carrier_blocked = TRUE AND carrier_block_reason = 'consent_api_sync'",
            [],
            $rows
        );
    }
}
