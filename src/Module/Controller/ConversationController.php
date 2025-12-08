<?php

/**
 * Conversation Controller - View and interact with message threads
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Controller;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\MessagePollingService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class ConversationController
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly MessagePollingService $pollingService,
        private readonly MessageService $messageService,
        private readonly Environment $twig
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Dispatch action to appropriate method
     *
     * @param string $action
     * @return Response
     */
    public function dispatch(string $action): Response
    {
        $request = Request::createFromGlobals();

        return match ($action) {
            'view' => $this->showThread($request),
            'reply' => $this->handleReply($request),
            default => $this->showThread($request),
        };
    }

    /**
     * Show conversation thread
     *
     * @param Request $request
     * @return Response
     */
    private function showThread(Request $request): Response
    {
        $conversationId = (string)$request->query->get('conversation_id', '');

        if (empty($conversationId)) {
            throw new ValidationException("Conversation ID is required");
        }

        $this->pollingService->pollConversation($conversationId);

        $sql = "SELECT * FROM oce_sinch_conversations WHERE conversation_id = ?";
        $conversation = QueryUtils::querySingleRow($sql, [$conversationId]);

        if (!$conversation) {
            throw new ValidationException("Conversation not found");
        }

        $sql = "SELECT pd.fname, pd.lname, pd.phone_cell
                FROM patient_data pd
                WHERE pd.pid = ?";
        $patient = QueryUtils::querySingleRow($sql, [$conversation['patient_id']]);

        $sql = "SELECT * FROM oce_sinch_messages
                WHERE conversation_id = ?
                ORDER BY sent_at ASC";
        $messages = QueryUtils::fetchRecords($sql, [$conversationId]);

        $content = $this->twig->render('conversation/thread.html.twig', [
            'conversation' => $conversation,
            'patient' => $patient,
            'messages' => $messages,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
        ]);

        $response = new Response($content);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    /**
     * Handle reply to conversation
     *
     * @param Request $request
     * @return Response
     */
    private function handleReply(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirect($request);
        }

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''))) {
            throw new AccessDeniedException("CSRF token verification failed");
        }

        $conversationId = (string)$request->request->get('conversation_id', '');
        $messageBody = (string)$request->request->get('message', '');

        if (empty($conversationId) || empty($messageBody)) {
            throw new ValidationException("Conversation ID and message are required");
        }

        $sql = "SELECT patient_id FROM oce_sinch_conversations WHERE conversation_id = ?";
        $conversation = QueryUtils::querySingleRow($sql, [$conversationId]);

        if (!$conversation) {
            throw new ValidationException("Conversation not found");
        }

        $sql = "SELECT phone_cell FROM patient_data WHERE pid = ?";
        $patient = QueryUtils::querySingleRow($sql, [$conversation['patient_id']]);

        if (!$patient || empty($patient['phone_cell'])) {
            throw new ValidationException("Patient phone number not found");
        }

        try {
            $this->messageService->sendToPatient(
                (int)$conversation['patient_id'],
                $patient['phone_cell'],
                $messageBody
            );

            $_SESSION['conversation_message'] = "Message sent successfully";
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send reply: " . $e->getMessage());
            $_SESSION['conversation_message'] = "Error sending message: " . $e->getMessage();
        }

        return $this->redirect($request, $conversationId);
    }

    /**
     * Redirect back to conversation
     *
     * @param Request $request
     * @param string|null $conversationId
     * @return RedirectResponse
     */
    private function redirect(Request $request, ?string $conversationId = null): RedirectResponse
    {
        $queryParams = $request->query->all();
        unset($queryParams['action']);

        if ($conversationId) {
            $queryParams['conversation_id'] = $conversationId;
        }

        $queryString = http_build_query($queryParams);
        $scriptName = $request->server->get(
            'SCRIPT_NAME',
            '/interface/modules/custom_modules/oce-module-sinch-conversations/public/conversation.php'
        );
        $uri = $scriptName . ($queryString ? '?' . $queryString : '');

        return new RedirectResponse($uri);
    }
}
