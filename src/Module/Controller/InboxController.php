<?php

/**
 * Inbox Controller - Message list and dashboard
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
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class InboxController
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly MessagePollingService $pollingService,
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
            'refresh' => $this->handleRefresh($request),
            'list', 'default' => $this->showInbox(),
            default => $this->showInbox(),
        };
    }

    /**
     * Show inbox with conversation list
     *
     * @return Response
     */
    private function showInbox(): Response
    {
        $sql = "SELECT c.*,
                       pd.fname, pd.lname,
                       COUNT(CASE WHEN m.direction = 'inbound' AND m.status != 'READ' THEN 1 END) as unread_count,
                       MAX(m.sent_at) as last_activity
                FROM oce_sinch_conversations c
                LEFT JOIN patient_data pd ON c.patient_id = pd.pid
                LEFT JOIN oce_sinch_messages m ON c.conversation_id = m.conversation_id
                GROUP BY c.id
                ORDER BY last_activity DESC
                LIMIT 50";

        $conversations = QueryUtils::fetchRecords($sql, []);

        foreach ($conversations as &$conversation) {
            $conversation['patient_name'] = trim(
                ($conversation['fname'] ?? '') . ' ' . ($conversation['lname'] ?? '')
            ) ?: 'Unknown';
        }

        $content = $this->twig->render('inbox/list.html.twig', [
            'conversations' => $conversations,
            'success_message' => $_SESSION['inbox_message'] ?? null,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
        ]);

        unset($_SESSION['inbox_message']);

        $response = new Response($content);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    /**
     * Handle refresh action - poll for new messages
     *
     * @param Request $request
     * @return Response
     */
    private function handleRefresh(Request $request): Response
    {
        if (!CsrfUtils::verifyCsrfToken($request->query->get('csrf_token', ''))) {
            throw new AccessDeniedException("CSRF token verification failed");
        }

        try {
            $newMessageCount = $this->pollingService->pollAllConversations();

            $_SESSION['inbox_message'] = $newMessageCount > 0
            ? "Found {$newMessageCount} new message(s)"
            : "No new messages";
        } catch (\Throwable $e) {
            $this->logger->error("Failed to refresh messages: " . $e->getMessage());
            $_SESSION['inbox_message'] = "Error refreshing messages: " . $e->getMessage();
        }

        return $this->redirect($request);
    }

    /**
     * Redirect back to inbox
     *
     * @param Request $request
     * @return RedirectResponse
     */
    private function redirect(Request $request): RedirectResponse
    {
        $queryParams = $request->query->all();
        unset($queryParams['action']);
        unset($queryParams['csrf_token']);

        $queryString = http_build_query($queryParams);
        $scriptName = $request->server->get(
            'SCRIPT_NAME',
            '/interface/modules/custom_modules/oce-module-sinch-conversations/public/index.php'
        );
        $uri = $queryString ? $scriptName . '?' . $queryString : $scriptName;

        return new RedirectResponse($uri);
    }
}
