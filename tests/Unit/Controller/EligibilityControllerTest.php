<?php

/**
 * Unit tests for EligibilityController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchConversations\Controller\EligibilityController;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\SkipReason;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EligibilityControllerTest extends TestCase
{
    private MessageService&MockObject $messageService;
    private EligibilityController $controller;

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

        $this->messageService = $this->createMock(MessageService::class);
        $this->controller = new EligibilityController(
            $this->messageService,
            new EligibilityAlertRenderer(),
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

    public function testValidPidReturnsRenderedAlert(): void
    {
        $_GET['pid'] = '42';
        $this->messageService->expects($this->once())
            ->method('diagnose')
            ->with(42)
            ->willReturn([
                'can_send' => true,
                'reason' => null,
                'context' => [],
                'phone' => '+15551112222',
            ]);

        $response = $this->controller->dispatch('html');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('alert-success', $body);
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, $body);
    }

    public function testNotEligibleVerdictPassesThroughToRenderer(): void
    {
        $_GET['pid'] = '7';
        $this->messageService->method('diagnose')->willReturn([
            'can_send' => false,
            'reason' => SkipReason::ModuleOptOut->value,
            'context' => [],
            'phone' => '+15551112222',
        ]);

        $response = $this->controller->dispatch('html');

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('alert-warning', $body);
        $this->assertStringContainsString('opted out', $body);
    }

    public function testMissingPidReturnsEmptyPlaceholder(): void
    {
        $this->messageService->expects($this->never())->method('diagnose');

        $response = $this->controller->dispatch('html');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            '<div id="' . EligibilityAlertRenderer::PLACEHOLDER_ID . '"></div>',
            (string) $response->getContent()
        );
    }

    public function testNonNumericPidReturnsEmptyPlaceholder(): void
    {
        $_GET['pid'] = 'not-a-number';
        $this->messageService->expects($this->never())->method('diagnose');

        $response = $this->controller->dispatch('html');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, (string) $response->getContent());
        $this->assertStringNotContainsString('alert', (string) $response->getContent());
    }

    public function testZeroPidReturnsEmptyPlaceholder(): void
    {
        $_GET['pid'] = '0';
        $this->messageService->expects($this->never())->method('diagnose');

        $response = $this->controller->dispatch('html');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, (string) $response->getContent());
    }

    public function testNegativePidReturnsEmptyPlaceholder(): void
    {
        $_GET['pid'] = '-1';
        $this->messageService->expects($this->never())->method('diagnose');

        $response = $this->controller->dispatch('html');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, (string) $response->getContent());
    }

    public function testDiagnoseExceptionReturnsEmptyPlaceholderAndLogs(): void
    {
        // Same degraded-response policy as the listener: don't break the
        // calendar UI on a transient failure, but leave a trace for ops.
        $_GET['pid'] = '42';
        $this->messageService->method('diagnose')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $response = $this->controller->dispatch('html');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, (string) $response->getContent());

        $logs = SystemLogger::getLogs();
        $errorLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'error'
                && str_contains($log['message'], 'Failed to diagnose SMS eligibility for endpoint request')
        );
        $this->assertNotEmpty($errorLogs);
    }

    public function testUnknownActionFallsBackToHtmlRender(): void
    {
        // Single-action controller — defensive default keeps a typo'd
        // action from surfacing as a 500.
        $_GET['pid'] = '42';
        $this->messageService->method('diagnose')->willReturn([
            'can_send' => true,
            'reason' => null,
            'context' => [],
            'phone' => '+15551112222',
        ]);

        $response = $this->controller->dispatch('garbage');

        $this->assertSame(200, $response->getStatusCode());
    }
}
