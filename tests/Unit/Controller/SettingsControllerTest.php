<?php

/**
 * Unit tests for SettingsController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchConversations\Controller\SettingsController;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConfigService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateSyncService;
use OpenCoreEMR\Modules\SinchConversations\SessionAccessor;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class SettingsControllerTest extends TestCase
{
    private GlobalConfig $config;
    private ConfigService&MockObject $configService;
    private ConversationApiClient&MockObject $apiClient;
    private TemplateSyncService&MockObject $syncService;
    private SessionAccessor&MockObject $session;
    private Environment $twig;
    private SettingsController $controller;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        CsrfUtils::reset();
        SystemLogger::clearLogs();

        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_FILES = [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $this->config = new GlobalConfig(new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'proj-1',
            GlobalConfig::CONFIG_OPTION_APP_ID => 'app-1',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'key-1',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('secret-1'),
            GlobalConfig::CONFIG_OPTION_REGION => 'us',
            GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL => 'SMS',
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
        ]), new MockConfigFactory());

        $this->configService = $this->createMock(ConfigService::class);
        $this->apiClient = $this->createMock(ConversationApiClient::class);
        $this->syncService = $this->createMock(TemplateSyncService::class);
        $this->session = $this->createMock(SessionAccessor::class);

        $loader = new ArrayLoader([
            'settings/config.html.twig' => '<html>{{ settings.project_id }}|{{ success_message }}|ext:{{ is_external_config ? "yes" : "no" }}</html>',
        ]);
        $this->twig = new Environment($loader);

        $this->controller = new SettingsController(
            $this->config,
            $this->configService,
            $this->apiClient,
            $this->syncService,
            $this->session,
            $this->twig,
            new SystemLogger()
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_FILES = [];
    }

    // --- showSettings ---

    public function testShowSettingsRendersPage(): void
    {
        $response = $this->controller->dispatch('show');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('proj-1', $content);
    }

    public function testShowSettingsPassesExternalConfigFlag(): void
    {
        $response = $this->controller->dispatch('show');

        $content = $response->getContent();
        $this->assertIsString($content);
        // GlobalConfig caches isExternalConfigMode at construction; false in test environment
        $this->assertStringContainsString('ext:no', $content);
    }

    public function testDefaultActionShowsSettings(): void
    {
        $response = $this->controller->dispatch('default');

        $this->assertEquals(200, $response->getStatusCode());
    }

    // --- handleSave ---

    public function testSaveRedirectsOnGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('save');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSaveWithInvalidCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'invalid';
        CsrfUtils::setVerifyResult(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->dispatch('save');
    }

    public function testSaveSuccess(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['project_id'] = 'new-proj';
        $_POST['app_id'] = 'new-app';
        $_POST['api_key'] = 'new-key';
        $_POST['api_secret'] = 'new-secret';
        $_POST['region'] = 'eu';
        $_POST['default_channel'] = 'SMS';
        $_POST['clinic_name'] = 'New Clinic';
        $_POST['clinic_phone'] = '+15550000000';
        CsrfUtils::setVerifyResult(true);

        $this->apiClient->expects($this->once())
            ->method('validateCredentials')
            ->with('new-proj', 'new-app', 'new-key', 'new-secret', 'eu');
        $this->configService->expects($this->once())->method('saveSettings');
        $this->session->expects($this->once())
            ->method('setFlash')
            ->with('settings_message', 'Settings saved successfully');

        $response = $this->controller->dispatch('save');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSaveAbortsWhenCredentialValidationFails(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['project_id'] = 'new-proj';
        $_POST['app_id'] = 'new-app';
        $_POST['api_key'] = 'new-key';
        $_POST['api_secret'] = 'new-secret';
        $_POST['region'] = 'us';
        CsrfUtils::setVerifyResult(true);

        $this->apiClient->method('validateCredentials')
            ->willThrowException(new ApiException('OAuth2 authentication failed: Bad credentials', 401));
        $this->configService->expects($this->never())->method('saveSettings');
        $this->session->expects($this->once())
            ->method('setFlash')
            ->with(
                'settings_message',
                $this->callback(function (string $message): bool {
                    return str_contains($message, 'could not be verified')
                        && str_contains($message, '(ref:')
                        && !str_contains($message, 'Bad credentials');
                })
            );

        $response = $this->controller->dispatch('save');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSaveSkipsValidationWhenApiFieldsUnchanged(): void
    {
        // The real settings form re-posts existing API values even on a
        // clinic-name-only edit. Validation must skip in that case so an
        // unrelated edit doesn't make a network call (or get blocked when
        // Sinch is down).
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['project_id'] = 'proj-1';
        $_POST['app_id'] = 'app-1';
        $_POST['api_key'] = 'key-1';
        $_POST['region'] = 'us';
        $_POST['api_secret'] = ''; // Left blank → keep stored secret
        $_POST['clinic_name'] = 'Renamed Clinic';
        CsrfUtils::setVerifyResult(true);

        $this->apiClient->expects($this->never())->method('validateCredentials');
        $this->configService->expects($this->once())->method('saveSettings');

        $this->controller->dispatch('save');
    }

    public function testSaveValidatesWhenApiKeyChanges(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['project_id'] = 'proj-1';
        $_POST['app_id'] = 'app-1';
        $_POST['api_key'] = 'rotated-key';
        $_POST['region'] = 'us';
        $_POST['api_secret'] = ''; // Reuses stored secret
        CsrfUtils::setVerifyResult(true);

        // api_key changed → validation runs, reusing the stored secret
        // (decoded value of base64('secret-1') from setUp).
        $this->apiClient->expects($this->once())
            ->method('validateCredentials')
            ->with('proj-1', 'app-1', 'rotated-key', $this->anything(), 'us');
        $this->configService->expects($this->once())->method('saveSettings');

        $this->controller->dispatch('save');
    }

    public function testSaveExcludesAllFieldsWhenExternalConfig(): void
    {
        $previousEnvConfig = getenv('OCE_SINCH_CONVERSATIONS_ENV_CONFIG');
        putenv('OCE_SINCH_CONVERSATIONS_ENV_CONFIG=1');

        try {
            // Rebuild controller so GlobalConfig caches the env var
            $this->config = new GlobalConfig(new MockGlobalsAccessor([
                GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'proj-1',
                GlobalConfig::CONFIG_OPTION_APP_ID => 'app-1',
                GlobalConfig::CONFIG_OPTION_API_KEY => 'key-1',
                GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('secret-1'),
                GlobalConfig::CONFIG_OPTION_REGION => 'us',
                GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL => 'SMS',
                GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
                GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
            ]), new MockConfigFactory());
            $this->controller = new SettingsController(
                $this->config,
                $this->configService,
                $this->apiClient,
                $this->syncService,
                $this->session,
                $this->twig,
                new SystemLogger()
            );

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['csrf_token'] = 'valid';
            $_POST['project_id'] = 'should-be-ignored';
            $_POST['app_id'] = 'should-be-ignored';
            $_POST['api_key'] = 'should-be-ignored';
            $_POST['api_secret'] = 'should-be-ignored';
            $_POST['region'] = 'eu';
            $_POST['default_channel'] = 'SMS';
            $_POST['clinic_name'] = 'New Clinic';
            $_POST['clinic_phone'] = '+15550000000';
            CsrfUtils::setVerifyResult(true);

            // saveSettings should never be called in external config mode
            $this->configService->expects($this->never())
                ->method('saveSettings');

            $this->session->expects($this->once())
                ->method('setFlash')
                ->with('settings_message', $this->stringContains('cannot be changed'));

            $response = $this->controller->dispatch('save');

            $this->assertInstanceOf(RedirectResponse::class, $response);
        } finally {
            if ($previousEnvConfig === false) {
                putenv('OCE_SINCH_CONVERSATIONS_ENV_CONFIG');
            } else {
                putenv('OCE_SINCH_CONVERSATIONS_ENV_CONFIG=' . $previousEnvConfig);
            }
        }
    }

    public function testSaveHandlesValidationError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['project_id'] = '';
        $_POST['app_id'] = 'app';
        $_POST['api_key'] = 'key';
        CsrfUtils::setVerifyResult(true);

        $this->configService->method('saveSettings')
            ->willThrowException(new ValidationException('Project ID is required'));

        $this->session->expects($this->once())
            ->method('setFlash')
            ->with(
                'settings_message',
                $this->callback(function (string $message): bool {
                    return str_contains($message, 'Error saving settings')
                        && str_contains($message, '(ref:')
                        && !str_contains($message, 'Project ID is required');
                })
            );

        $response = $this->controller->dispatch('save');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSaveHandlesUnexpectedError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['clinic_name'] = 'Test';
        CsrfUtils::setVerifyResult(true);

        $this->configService->method('saveSettings')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->session->expects($this->once())
            ->method('setFlash')
            ->with(
                'settings_message',
                $this->callback(function (string $message): bool {
                    return str_contains($message, 'Error saving settings')
                        && str_contains($message, '(ref:')
                        && !str_contains($message, 'DB error');
                })
            );

        $response = $this->controller->dispatch('save');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSaveIncludesSecretWhenProvided(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['clinic_name'] = 'Test';
        $_POST['api_secret'] = 'new-secret';
        CsrfUtils::setVerifyResult(true);

        $this->configService->expects($this->once())
            ->method('saveSettings')
            ->with($this->callback(fn($s) => ($s['api_secret'] ?? '') === 'new-secret'));

        $this->controller->dispatch('save');
    }

    // --- handleTest ---

    public function testTestWithInvalidCsrf(): void
    {
        $_GET['csrf_token'] = 'invalid';
        CsrfUtils::setVerifyResult(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->dispatch('test');
    }

    public function testTestReturnsBadRequestWhenNotConfigured(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([]), new MockConfigFactory());
        $controller = new SettingsController(
            $config,
            $this->configService,
            $this->apiClient,
            $this->syncService,
            $this->session,
            $this->twig,
            new SystemLogger()
        );

        $_GET['csrf_token'] = 'valid';
        CsrfUtils::setVerifyResult(true);

        $response = $controller->dispatch('test');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testTestSuccessReturnsAppConfig(): void
    {
        $_GET['csrf_token'] = 'valid';
        CsrfUtils::setVerifyResult(true);

        $this->apiClient->expects($this->once())->method('testConnection');
        $this->apiClient->method('getApp')->willReturn([
            'display_name' => 'Test App',
            'channel_credentials' => [],
        ]);

        $response = $this->controller->dispatch('test');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent() ?: '', true);
        $this->assertTrue($content['success']);
    }

    public function testTestHandlesApiFailure(): void
    {
        $_GET['csrf_token'] = 'valid';
        CsrfUtils::setVerifyResult(true);

        $this->apiClient->method('testConnection')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $response = $this->controller->dispatch('test');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $content = json_decode($response->getContent() ?: '', true);
        $this->assertFalse($content['success']);
    }

    // --- handleTestSms ---

    public function testTestSmsRejectsGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('test-sms');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
    }

    public function testTestSmsWithInvalidCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'invalid';
        CsrfUtils::setVerifyResult(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->dispatch('test-sms');
    }

    public function testTestSmsMissingPhoneNumber(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['phone_number'] = '';
        $_POST['message'] = 'Test';
        CsrfUtils::setVerifyResult(true);

        $response = $this->controller->dispatch('test-sms');

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testTestSmsMissingMessage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['phone_number'] = '+15559999999';
        $_POST['message'] = '';
        CsrfUtils::setVerifyResult(true);

        $response = $this->controller->dispatch('test-sms');

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testTestSmsSuccess(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['phone_number'] = '+15559999999';
        $_POST['message'] = 'Test SMS';
        CsrfUtils::setVerifyResult(true);

        $this->apiClient->expects($this->once())
            ->method('sendMessageByChannelIdentity')
            ->with('+15559999999', 'Test SMS', 'SMS', $this->anything());

        $response = $this->controller->dispatch('test-sms');

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent() ?: '', true);
        $this->assertTrue($content['success']);
    }

    public function testTestSmsHandlesApiFailure(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['phone_number'] = '+15559999999';
        $_POST['message'] = 'Test SMS';
        CsrfUtils::setVerifyResult(true);

        $this->apiClient->method('sendMessageByChannelIdentity')
            ->willThrowException(new \RuntimeException('API error'));

        $response = $this->controller->dispatch('test-sms');

        $this->assertEquals(500, $response->getStatusCode());
    }

    // --- handleSyncTemplates ---

    public function testSyncTemplatesWithInvalidCsrf(): void
    {
        $_GET['csrf_token'] = 'invalid';
        CsrfUtils::setVerifyResult(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->dispatch('sync-templates');
    }

    public function testSyncTemplatesReturnsBadRequestWhenNotConfigured(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([]), new MockConfigFactory());
        $controller = new SettingsController(
            $config,
            $this->configService,
            $this->apiClient,
            $this->syncService,
            $this->session,
            $this->twig,
            new SystemLogger()
        );

        $_GET['csrf_token'] = 'valid';
        CsrfUtils::setVerifyResult(true);

        $response = $controller->dispatch('sync-templates');

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSyncTemplatesSuccess(): void
    {
        $_GET['csrf_token'] = 'valid';
        CsrfUtils::setVerifyResult(true);

        $this->syncService->method('syncAllTemplates')->willReturn([
            'created' => 2,
            'updated' => 1,
            'skipped' => 0,
            'failed' => 0,
            'total' => 3,
        ]);

        $response = $this->controller->dispatch('sync-templates');

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent() ?: '', true);
        $this->assertTrue($content['success']);
    }

    public function testSyncTemplatesPartialFailure(): void
    {
        $_GET['csrf_token'] = 'valid';
        CsrfUtils::setVerifyResult(true);

        $this->syncService->method('syncAllTemplates')->willReturn([
            'created' => 1,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 1,
            'total' => 2,
        ]);

        $response = $this->controller->dispatch('sync-templates');

        $this->assertEquals(Response::HTTP_PARTIAL_CONTENT, $response->getStatusCode());
        $content = json_decode($response->getContent() ?: '', true);
        $this->assertFalse($content['success']);
    }

    public function testSyncTemplatesHandlesError(): void
    {
        $_GET['csrf_token'] = 'valid';
        CsrfUtils::setVerifyResult(true);

        $this->syncService->method('syncAllTemplates')
            ->willThrowException(new \RuntimeException('Sync failed'));

        $response = $this->controller->dispatch('sync-templates');

        $this->assertEquals(500, $response->getStatusCode());
    }
}
