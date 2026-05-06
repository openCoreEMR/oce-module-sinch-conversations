<?php

/**
 * Unit tests for AppointmentSmsStatusJsListener
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Listener;

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\Listener\AppointmentSmsStatusJsListener;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use PHPUnit\Framework\TestCase;

class AppointmentSmsStatusJsListenerTest extends TestCase
{
    private string $savedWebroot;

    protected function setUp(): void
    {
        $this->savedWebroot = is_string($GLOBALS['webroot'] ?? null) ? $GLOBALS['webroot'] : '';
        $GLOBALS['webroot'] = '/openemr';
    }

    protected function tearDown(): void
    {
        $GLOBALS['webroot'] = $this->savedWebroot;
    }

    public function testEchoesScriptReferencingPlaceholderIdAndEventName(): void
    {
        $js = $this->captureRender();

        // Pinning constant references — a renamed placeholder id or event
        // name in the corresponding renderer/listener will fail this test
        // and force the JS to be updated alongside.
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, $js);
        $this->assertStringContainsString(AppointmentSmsStatusJsListener::PATIENT_SET_EVENT, $js);
    }

    public function testEchoesScriptReferencingFormPidInputName(): void
    {
        $js = $this->captureRender();

        // The form_pid hidden input name is set by core's add_edit_event.php
        // (line 1423). If core ever renames it, this test fails loudly.
        $this->assertStringContainsString('form_pid', $js);
    }

    public function testEchoesScriptReferencingEligibilityEndpointUrl(): void
    {
        $js = $this->captureRender();

        // URL is embedded via json_encode, which escapes forward slashes;
        // the JS source therefore contains the escaped form rather than
        // the literal path.
        $expectedPath = '/openemr/interface/modules/custom_modules/'
            . Bootstrap::MODULE_NAME
            . '/public/eligibility.php';
        $this->assertStringContainsString(
            (string) json_encode($expectedPath),
            $js
        );
    }

    public function testEchoesScriptUsingAbortControllerForRaceProtection(): void
    {
        $js = $this->captureRender();

        $this->assertStringContainsString('AbortController', $js);
    }

    public function testEchoesScriptListeningForDomContentLoaded(): void
    {
        $js = $this->captureRender();

        $this->assertStringContainsString('DOMContentLoaded', $js);
    }

    public function testEchoesScriptUsingFetchWithSameOriginCredentials(): void
    {
        // Without credentials: 'same-origin' the session cookie is not
        // sent and the endpoint's ACL check would always reject the
        // request as unauthenticated.
        $js = $this->captureRender();

        $this->assertStringContainsString("credentials: 'same-origin'", $js);
    }

    public function testEmbedsUrlAsJsonToProtectAgainstQuoteInjection(): void
    {
        // If a future webroot value contained a quote, embedding it
        // unencoded would break out of the JS string. json_encode keeps
        // the value safe regardless of contents.
        $GLOBALS['webroot'] = '/foo"bar';

        $js = $this->captureRender();

        // json_encode escapes " as \" — the raw literal must not survive.
        $this->assertStringNotContainsString('"/foo"bar/interface/', $js);
        $this->assertStringContainsString('/foo\\"bar', $js);
    }

    private function captureRender(): string
    {
        $listener = new AppointmentSmsStatusJsListener();
        ob_start();
        $listener->onRenderJavascript(new AppointmentRenderEvent([]));
        return (string) ob_get_clean();
    }
}
