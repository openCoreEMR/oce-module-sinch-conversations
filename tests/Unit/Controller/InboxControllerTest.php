<?php

/**
 * Unit tests for InboxController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchConversations\Controller\InboxController;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\MessagePollingService;
use OpenCoreEMR\Modules\SinchConversations\SessionAccessor;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class InboxControllerTest extends TestCase
{
    private GlobalConfig $config;
    private MessagePollingService&MockObject $pollingService;
    private SessionAccessor&MockObject $session;
    private Environment $twig;
    private InboxController $controller;

    protected function setUp(): void
    {
        // Clear mock data
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        CsrfUtils::reset();
        SystemLogger::clearLogs();

        // Initialize globals
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_FILES = [];

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Clear session
        $_SESSION = [];

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_APP_ID => 'test-app-id',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'us',
            'assets_static_relative' => '/assets',
        ]);

        $this->config = new GlobalConfig($mockGlobals);
        $this->pollingService = $this->createMock(MessagePollingService::class);
        $this->session = $this->createMock(SessionAccessor::class);

        // Create a simple Twig environment for testing
        $loader = new ArrayLoader([
            'inbox/list.html.twig' => '<html>{{ conversations|length }} conversations|' .
                '{{ success_message }}</html>',
        ]);
        $this->twig = new Environment($loader);

        $this->controller = new InboxController(
            $this->config,
            $this->pollingService,
            $this->session,
            $this->twig,
            new SystemLogger()
        );
    }

    protected function tearDown(): void
    {
        // Clean up session and globals
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_FILES = [];
    }

    public function testDispatchDefaultAction(): void
    {
        QueryUtils::setMockResult(
            "SELECT c.*,
                       pd.fname, pd.lname,
                       COUNT(CASE WHEN m.direction = 'inbound' AND m.status != 'READ' THEN 1 END) as unread_count,
                       MAX(m.sent_at) as last_activity
                FROM oce_sinch_conversations c
                LEFT JOIN patient_data pd ON c.patient_id = pd.pid
                LEFT JOIN oce_sinch_messages m ON c.conversation_id = m.conversation_id
                GROUP BY c.id
                ORDER BY last_activity DESC
                LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('default');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDispatchListAction(): void
    {
        QueryUtils::setMockResult(
            "SELECT c.*,
                       pd.fname, pd.lname,
                       COUNT(CASE WHEN m.direction = 'inbound' AND m.status != 'READ' THEN 1 END) as unread_count,
                       MAX(m.sent_at) as last_activity
                FROM oce_sinch_conversations c
                LEFT JOIN patient_data pd ON c.patient_id = pd.pid
                LEFT JOIN oce_sinch_messages m ON c.conversation_id = m.conversation_id
                GROUP BY c.id
                ORDER BY last_activity DESC
                LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowInboxWithConversations(): void
    {
        QueryUtils::setMockResult(
            "SELECT c.*,
                       pd.fname, pd.lname,
                       COUNT(CASE WHEN m.direction = 'inbound' AND m.status != 'READ' THEN 1 END) as unread_count,
                       MAX(m.sent_at) as last_activity
                FROM oce_sinch_conversations c
                LEFT JOIN patient_data pd ON c.patient_id = pd.pid
                LEFT JOIN oce_sinch_messages m ON c.conversation_id = m.conversation_id
                GROUP BY c.id
                ORDER BY last_activity DESC
                LIMIT 50",
            [],
            [
                [
                    'id' => 1,
                    'conversation_id' => 'conv-123',
                    'patient_id' => 100,
                    'fname' => 'John',
                    'lname' => 'Doe',
                    'unread_count' => 2,
                    'last_activity' => '2025-01-01 12:00:00',
                ],
                [
                    'id' => 2,
                    'conversation_id' => 'conv-456',
                    'patient_id' => 101,
                    'fname' => 'Jane',
                    'lname' => 'Smith',
                    'unread_count' => 0,
                    'last_activity' => '2025-01-01 11:00:00',
                ],
            ]
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('2 conversations', $content);
    }

    public function testShowInboxSetsXFrameOptionsHeader(): void
    {
        QueryUtils::setMockResult(
            "SELECT c.*,
                       pd.fname, pd.lname,
                       COUNT(CASE WHEN m.direction = 'inbound' AND m.status != 'READ' THEN 1 END) as unread_count,
                       MAX(m.sent_at) as last_activity
                FROM oce_sinch_conversations c
                LEFT JOIN patient_data pd ON c.patient_id = pd.pid
                LEFT JOIN oce_sinch_messages m ON c.conversation_id = m.conversation_id
                GROUP BY c.id
                ORDER BY last_activity DESC
                LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('list');

        $this->assertEquals('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testShowInboxDisplaysFlashMessages(): void
    {
        $this->session->expects($this->once())
            ->method('getFlash')
            ->with('inbox_message')
            ->willReturn('Test message');

        QueryUtils::setMockResult(
            "SELECT c.*,
                       pd.fname, pd.lname,
                       COUNT(CASE WHEN m.direction = 'inbound' AND m.status != 'READ' THEN 1 END) as unread_count,
                       MAX(m.sent_at) as last_activity
                FROM oce_sinch_conversations c
                LEFT JOIN patient_data pd ON c.patient_id = pd.pid
                LEFT JOIN oce_sinch_messages m ON c.conversation_id = m.conversation_id
                GROUP BY c.id
                ORDER BY last_activity DESC
                LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('list');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Test message', $content);
    }

    public function testShowInboxWithMissingPatientName(): void
    {
        QueryUtils::setMockResult(
            "SELECT c.*,
                       pd.fname, pd.lname,
                       COUNT(CASE WHEN m.direction = 'inbound' AND m.status != 'READ' THEN 1 END) as unread_count,
                       MAX(m.sent_at) as last_activity
                FROM oce_sinch_conversations c
                LEFT JOIN patient_data pd ON c.patient_id = pd.pid
                LEFT JOIN oce_sinch_messages m ON c.conversation_id = m.conversation_id
                GROUP BY c.id
                ORDER BY last_activity DESC
                LIMIT 50",
            [],
            [
                [
                    'id' => 1,
                    'conversation_id' => 'conv-789',
                    'patient_id' => null,
                    'fname' => null,
                    'lname' => null,
                    'unread_count' => 0,
                    'last_activity' => null,
                ],
            ]
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleRefreshWithInvalidCsrf(): void
    {
        $_GET['csrf_token'] = 'invalid-token';
        CsrfUtils::setVerifyResult(false);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('CSRF token verification failed');

        $this->controller->dispatch('refresh');
    }

    public function testHandleRefreshWithValidCsrf(): void
    {
        $_GET['csrf_token'] = 'valid-token';
        CsrfUtils::setVerifyResult(true);

        $this->pollingService->expects($this->once())
            ->method('pollAllConversations')
            ->willReturn(['total_messages' => 5, 'keyword_failures' => []]);

        $this->session->expects($this->once())
            ->method('setFlash')
            ->with('inbox_message', 'Found 5 new message(s)');

        $response = $this->controller->dispatch('refresh');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testHandleRefreshWithNoNewMessages(): void
    {
        $_GET['csrf_token'] = 'valid-token';
        CsrfUtils::setVerifyResult(true);

        $this->pollingService->expects($this->once())
            ->method('pollAllConversations')
            ->willReturn(['total_messages' => 0, 'keyword_failures' => []]);

        $this->session->expects($this->once())
            ->method('setFlash')
            ->with('inbox_message', 'No new messages');

        $response = $this->controller->dispatch('refresh');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testHandleRefreshWithPollingError(): void
    {
        $_GET['csrf_token'] = 'valid-token';
        CsrfUtils::setVerifyResult(true);

        $this->pollingService->expects($this->once())
            ->method('pollAllConversations')
            ->willThrowException(new \Exception('API error'));

        $this->session->expects($this->once())
            ->method('setFlash')
            ->with(
                'inbox_message',
                $this->callback(function (string $message): bool {
                    $this->assertStringContainsString('Error refreshing messages', $message);
                    $this->assertMatchesRegularExpression('/\(ref: [0-9a-f]{8}\)/', $message);
                    $this->assertStringNotContainsString('API error', $message);

                    return true;
                })
            );

        $response = $this->controller->dispatch('refresh');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testRedirectUsesScriptName(): void
    {
        $_GET['csrf_token'] = 'valid-token';
        $_SERVER['SCRIPT_NAME'] = '/interface/modules/custom_modules/oce-module-sinch-conversations/public/index.php';
        CsrfUtils::setVerifyResult(true);

        $this->pollingService->expects($this->once())
            ->method('pollAllConversations')
            ->willReturn(['total_messages' => 0, 'keyword_failures' => []]);

        $response = $this->controller->dispatch('refresh');

        $this->assertInstanceOf(RedirectResponse::class, $response);

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('/interface/modules/custom_modules/', $location);
    }

    public function testRedirectRemovesCsrfAndActionParams(): void
    {
        $_GET['csrf_token'] = 'valid-token';
        $_GET['action'] = 'refresh';
        $_GET['filter'] = 'active';
        CsrfUtils::setVerifyResult(true);

        $this->pollingService->expects($this->once())
            ->method('pollAllConversations')
            ->willReturn(['total_messages' => 0, 'keyword_failures' => []]);

        $response = $this->controller->dispatch('refresh');

        $this->assertInstanceOf(RedirectResponse::class, $response);

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('filter=active', $location);
        $this->assertStringNotContainsString('csrf_token', $location);
        $this->assertStringNotContainsString('action=', $location);
    }
}
