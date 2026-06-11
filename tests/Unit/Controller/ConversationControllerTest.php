<?php

/**
 * Unit tests for ConversationController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchConversations\Controller\ConversationController;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\MessagePollingService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\SessionAccessor;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ConversationControllerTest extends TestCase
{
    private GlobalConfig $config;
    private MessagePollingService&MockObject $pollingService;
    private MessageService&MockObject $messageService;
    private SessionAccessor&MockObject $session;
    private SessionInterface&MockObject $csrfSession;
    private Environment $twig;
    private ConversationController $controller;

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
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project',
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
        ]), new MockConfigFactory());
        $this->pollingService = $this->createMock(MessagePollingService::class);
        $this->messageService = $this->createMock(MessageService::class);
        $this->session = $this->createMock(SessionAccessor::class);
        $this->csrfSession = $this->createMock(SessionInterface::class);

        $loader = new ArrayLoader([
            'conversation/thread.html.twig' => '<html>{{ messages|length }} messages|{{ conversation.conversation_id }}</html>',
        ]);
        $this->twig = new Environment($loader);

        $this->controller = new ConversationController(
            $this->config,
            $this->pollingService,
            $this->messageService,
            $this->session,
            $this->csrfSession,
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

    // --- showThread ---

    public function testDispatchDefaultShowsThread(): void
    {
        $_GET['conversation_id'] = 'conv-1';
        $this->mockConversationData('conv-1');

        $this->pollingService->expects($this->once())
            ->method('pollConversation')
            ->with('conv-1');

        $response = $this->controller->dispatch('view');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testShowThreadThrowsWhenNoConversationId(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Conversation ID is required');

        $this->controller->dispatch('view');
    }

    public function testShowThreadThrowsWhenConversationNotFound(): void
    {
        $_GET['conversation_id'] = 'conv-missing';

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-missing'],
            []
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Conversation not found');

        $this->controller->dispatch('view');
    }

    public function testShowThreadRendersMessages(): void
    {
        $_GET['conversation_id'] = 'conv-1';
        $this->mockConversationData('conv-1', 3);

        $response = $this->controller->dispatch('view');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('3 messages', $content);
        $this->assertStringContainsString('conv-1', $content);
    }

    // --- handleReply ---

    public function testReplyWithInvalidCsrf(): void
    {
        $_POST['csrf_token'] = 'invalid';
        $_POST['conversation_id'] = 'conv-1';
        $_POST['message'] = 'Hello';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        CsrfUtils::setVerifyResult(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->dispatch('reply');
    }

    public function testReplyRedirectsOnGet(): void
    {
        // GET request to reply action should redirect
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('reply');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testReplyThrowsWhenMissingFields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['conversation_id'] = '';
        $_POST['message'] = '';
        CsrfUtils::setVerifyResult(true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Conversation ID and message are required');

        $this->controller->dispatch('reply');
    }

    public function testReplyThrowsWhenConversationNotFound(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['conversation_id'] = 'conv-missing';
        $_POST['message'] = 'Hello';
        CsrfUtils::setVerifyResult(true);

        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-missing'],
            []
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Conversation not found');

        $this->controller->dispatch('reply');
    }

    public function testReplyThrowsWhenPatientHasNoPhone(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['conversation_id'] = 'conv-1';
        $_POST['message'] = 'Hello';
        CsrfUtils::setVerifyResult(true);

        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['patient_id' => 42]]
        );
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['phone_cell' => '']]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Patient phone number not found');

        $this->controller->dispatch('reply');
    }

    public function testReplySuccessSendsMessageAndRedirects(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['conversation_id'] = 'conv-1';
        $_POST['message'] = 'Reply text';
        CsrfUtils::setVerifyResult(true);

        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['patient_id' => 42]]
        );
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['phone_cell' => '+15559999999']]
        );

        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->with(42, '+15559999999', 'Reply text');

        $this->session->expects($this->once())
            ->method('setFlash')
            ->with('conversation_message', 'Message sent successfully');

        $response = $this->controller->dispatch('reply');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testReplyHandlesSendFailure(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['conversation_id'] = 'conv-1';
        $_POST['message'] = 'Reply text';
        CsrfUtils::setVerifyResult(true);

        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['patient_id' => 42]]
        );
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['phone_cell' => '+15559999999']]
        );

        $this->messageService->method('sendToPatient')
            ->willThrowException(new \RuntimeException('API error'));

        $this->session->expects($this->once())
            ->method('setFlash')
            ->with('conversation_message', $this->stringContains('Error sending message'));

        $response = $this->controller->dispatch('reply');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testReplyNormalizesPhoneToE164(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['conversation_id'] = 'conv-1';
        $_POST['message'] = 'Reply text';
        CsrfUtils::setVerifyResult(true);

        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['patient_id' => 42]]
        );
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['phone_cell' => '(555) 999-9999']]
        );

        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->with(42, '+15559999999', 'Reply text');

        $this->controller->dispatch('reply');
    }

    public function testReplyThrowsWhenPhoneCannotBeNormalized(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'valid';
        $_POST['conversation_id'] = 'conv-1';
        $_POST['message'] = 'Reply text';
        CsrfUtils::setVerifyResult(true);

        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['patient_id' => 42]]
        );
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['phone_cell' => 'not-a-phone']]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not a valid phone number');

        $this->controller->dispatch('reply');
    }

    // --- Helpers ---

    private function mockConversationData(string $conversationId, int $messageCount = 0): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_conversations WHERE conversation_id = ?",
            [$conversationId],
            [['id' => 1, 'conversation_id' => $conversationId, 'patient_id' => 42, 'status' => 'ACTIVE']]
        );
        QueryUtils::setMockResult(
            "SELECT pd.fname, pd.lname, pd.phone_cell
                FROM patient_data pd
                WHERE pd.pid = ?",
            [42],
            [['fname' => 'John', 'lname' => 'Doe', 'phone_cell' => '+15559999999']]
        );

        $messages = [];
        for ($i = 0; $i < $messageCount; $i++) {
            $messages[] = [
                'id' => $i + 1,
                'message_id' => 'msg-' . ($i + 1),
                'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                'body' => 'Message ' . ($i + 1),
                'sent_at' => '2026-01-01 12:00:0' . $i,
            ];
        }

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_messages
                WHERE conversation_id = ?
                ORDER BY sent_at ASC",
            [$conversationId],
            $messages
        );
    }
}
