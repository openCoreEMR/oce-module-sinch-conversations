<?php

/**
 * Unit tests for WebhookProvisioningService
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
use OpenCoreEMR\Modules\SinchConversations\Service\ConfigService;
use OpenCoreEMR\Modules\SinchConversations\Service\WebhookProvisioningService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\AppConfigurationClient;
use OpenCoreEMR\Sinch\Conversation\Exception\ConfigurationException;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebhookProvisioningServiceTest extends TestCase
{
    private const TENANT_HOST = 'tenant-a.example.com';
    private const TENANT_SITE_ADDR = 'https://tenant-a.example.com';
    private const TENANT_TARGET = 'https://tenant-a.example.com'
        . GlobalConfig::WEBHOOK_PATH;

    private AppConfigurationClient&MockObject $appClient;
    private ConfigService&MockObject $configService;

    protected function setUp(): void
    {
        $this->appClient = $this->createMock(AppConfigurationClient::class);
        $this->configService = $this->createMock(ConfigService::class);
    }

    private function makeService(string $siteAddr = self::TENANT_SITE_ADDR): WebhookProvisioningService
    {
        $config = new GlobalConfig(
            new MockGlobalsAccessor(['qualified_site_addr' => $siteAddr]),
            new MockConfigFactory()
        );
        return new WebhookProvisioningService($config, $this->appClient, $this->configService);
    }

    public function testGetStatusReturnsNotConfiguredWhenSiteAddressMissing(): void
    {
        $service = $this->makeService('');
        $this->appClient->expects($this->never())->method('listWebhooks');

        $status = $service->getStatus();

        $this->assertSame(WebhookProvisioningService::STATE_NOT_CONFIGURED, $status['state']);
        $this->assertNull($status['webhook']);
        $this->assertSame('', $status['target_url']);
    }

    public function testGetStatusReturnsNotProvisionedWhenNoMatchingWebhook(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([
            ['id' => 'wh-1', 'target' => 'https://other-tenant.example.com' . GlobalConfig::WEBHOOK_PATH, 'triggers' => ['MESSAGE_INBOUND']],
        ]);

        $status = $service->getStatus();

        $this->assertSame(WebhookProvisioningService::STATE_NOT_PROVISIONED, $status['state']);
        $this->assertNull($status['webhook']);
        $this->assertSame(1, $status['total_webhooks']);
        $this->assertSame(self::TENANT_TARGET, $status['target_url']);
    }

    public function testGetStatusReturnsActiveWhenAllTriggersMatch(): void
    {
        $service = $this->makeService();
        $ours = [
            'id' => 'wh-2',
            'target' => self::TENANT_TARGET,
            'triggers' => ['MESSAGE_INBOUND', 'MESSAGE_DELIVERY', 'OPT_IN', 'OPT_OUT'],
        ];
        $this->appClient->method('listWebhooks')->willReturn([$ours]);

        $status = $service->getStatus();

        $this->assertSame(WebhookProvisioningService::STATE_ACTIVE, $status['state']);
        $this->assertSame($ours, $status['webhook']);
        $this->assertTrue($status['triggers_match']);
    }

    public function testGetStatusReturnsNeedsUpdateWhenTriggersDiffer(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([[
            'id' => 'wh-3',
            'target' => self::TENANT_TARGET,
            'triggers' => ['MESSAGE_INBOUND'],
        ]]);

        $status = $service->getStatus();

        $this->assertSame(WebhookProvisioningService::STATE_NEEDS_UPDATE, $status['state']);
        $this->assertFalse($status['triggers_match']);
    }

    public function testGetStatusMatchesHostnameCaseInsensitively(): void
    {
        $service = $this->makeService('https://TENANT-A.example.com');
        $this->appClient->method('listWebhooks')->willReturn([[
            'id' => 'wh-4',
            'target' => 'https://tenant-a.example.com' . GlobalConfig::WEBHOOK_PATH,
            'triggers' => ['MESSAGE_INBOUND', 'MESSAGE_DELIVERY', 'OPT_IN', 'OPT_OUT'],
        ]]);

        $status = $service->getStatus();

        $this->assertSame(WebhookProvisioningService::STATE_ACTIVE, $status['state']);
    }

    public function testProvisionThrowsWhenSiteAddressMissing(): void
    {
        $service = $this->makeService('');

        $this->expectException(ConfigurationException::class);
        $service->provision();
    }

    public function testProvisionThrowsWhenWebhookAlreadyExists(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([[
            'id' => 'wh-5',
            'target' => self::TENANT_TARGET,
            'triggers' => WebhookProvisioningService::REQUIRED_TRIGGERS,
        ]]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/already exists/');
        $service->provision();
    }

    public function testProvisionThrowsWhenAtCapacity(): void
    {
        $service = $this->makeService();
        $foreignWebhooks = [];
        for ($i = 1; $i <= 5; $i++) {
            $foreignWebhooks[] = [
                'id' => "wh-$i",
                'target' => "https://other-$i.example.com" . GlobalConfig::WEBHOOK_PATH,
                'triggers' => ['MESSAGE_INBOUND'],
            ];
        }
        $this->appClient->method('listWebhooks')->willReturn($foreignWebhooks);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/limit/');
        $service->provision();
    }

    public function testProvisionCreatesWebhookAndSavesSecret(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([]);

        $createdWebhook = ['id' => 'wh-new', 'target' => self::TENANT_TARGET, 'triggers' => WebhookProvisioningService::REQUIRED_TRIGGERS];
        $this->appClient->expects($this->once())
            ->method('createWebhook')
            ->with($this->callback(function (array $data): bool {
                $this->assertSame(self::TENANT_TARGET, $data['target']);
                $this->assertSame(WebhookProvisioningService::REQUIRED_TRIGGERS, $data['triggers']);
                $this->assertIsString($data['secret']);
                $this->assertSame(64, strlen($data['secret']));
                return true;
            }))
            ->willReturn($createdWebhook);

        $this->configService->expects($this->once())
            ->method('saveWebhookSecret')
            ->with($this->isString());

        $result = $service->provision();
        $this->assertSame($createdWebhook, $result);
    }

    public function testUpdateThrowsWhenNoExistingWebhook(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/No webhook exists/');
        $service->update();
    }

    public function testUpdateRotatesSecretAndFixesTriggers(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([[
            'id' => 'wh-6',
            'target' => self::TENANT_TARGET,
            'triggers' => ['MESSAGE_INBOUND'],
        ]]);

        $updated = ['id' => 'wh-6', 'target' => self::TENANT_TARGET, 'triggers' => WebhookProvisioningService::REQUIRED_TRIGGERS];
        $this->appClient->expects($this->once())
            ->method('updateWebhook')
            ->with(
                'wh-6',
                $this->callback(function (array $data): bool {
                    $this->assertSame(WebhookProvisioningService::REQUIRED_TRIGGERS, $data['triggers']);
                    $this->assertIsString($data['secret']);
                    return true;
                })
            )
            ->willReturn($updated);

        $this->configService->expects($this->once())->method('saveWebhookSecret');

        $result = $service->update();
        $this->assertSame($updated, $result);
    }

    public function testRemoveDeletesWebhookAndClearsSecret(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([[
            'id' => 'wh-7',
            'target' => self::TENANT_TARGET,
            'triggers' => WebhookProvisioningService::REQUIRED_TRIGGERS,
        ]]);

        $this->appClient->expects($this->once())
            ->method('deleteWebhook')
            ->with('wh-7')
            ->willReturn(true);

        $this->configService->expects($this->once())->method('clearWebhookSecret');

        $this->assertTrue($service->remove());
    }

    public function testRemoveClearsSecretEvenWhenNothingRegistered(): void
    {
        $service = $this->makeService();
        $this->appClient->method('listWebhooks')->willReturn([]);

        $this->appClient->expects($this->never())->method('deleteWebhook');
        $this->configService->expects($this->once())->method('clearWebhookSecret');

        $this->assertFalse($service->remove());
    }
}
