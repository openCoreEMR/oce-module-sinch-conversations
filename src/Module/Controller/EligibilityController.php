<?php

/**
 * Returns the SMS-eligibility alert markup for a single patient
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Controller;

use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backs the public/eligibility.php endpoint that the calendar JS layer
 * fetches whenever the appointment form's patient changes (popup picker
 * swap, or initial load when the server-rendered listener could not
 * resolve a pid).
 *
 * Returns the same alert markup the server-side listener emits — the
 * EligibilityAlertRenderer is the single source of truth for both paths.
 * On any failure (missing pid, non-numeric pid, diagnose() throws), the
 * controller returns the empty placeholder div with HTTP 200 instead of
 * a status code that would surface as a console error and leave the
 * staff-facing badge in an indeterminate state.
 */
class EligibilityController
{
    public function __construct(
        private readonly MessageService $messageService,
        private readonly EligibilityAlertRenderer $renderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(string $action = 'html'): Response
    {
        return match ($action) {
            'html' => $this->renderHtml(),
            default => $this->renderHtml(),
        };
    }

    private function renderHtml(): Response
    {
        $request = Request::createFromGlobals();
        $rawPid = $request->query->get('pid');
        $pid = $this->coercePid($rawPid);

        if ($pid === null) {
            return $this->htmlResponse($this->renderer->renderEmpty());
        }

        try {
            $verdict = $this->messageService->diagnose($pid);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to diagnose SMS eligibility for endpoint request', [
                'patientId' => $pid,
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            return $this->htmlResponse($this->renderer->renderEmpty());
        }

        return $this->htmlResponse($this->renderer->render($verdict));
    }

    private function coercePid(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }
        $pid = (int) $value;
        return $pid > 0 ? $pid : null;
    }

    private function htmlResponse(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
