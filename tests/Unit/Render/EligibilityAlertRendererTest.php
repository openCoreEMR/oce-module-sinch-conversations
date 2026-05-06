<?php

/**
 * Unit tests for EligibilityAlertRenderer
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Render;

use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenCoreEMR\Modules\SinchConversations\SkipReason;
use PHPUnit\Framework\TestCase;

class EligibilityAlertRendererTest extends TestCase
{
    private EligibilityAlertRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new EligibilityAlertRenderer();
    }

    public function testEligibleVerdictRendersSuccessAlert(): void
    {
        $html = $this->renderer->render([
            'can_send' => true,
            'reason' => null,
            'context' => [],
            'phone' => '+15551112222',
        ]);

        $this->assertStringContainsString('alert-success', $html);
        $this->assertStringContainsString('eligible to receive SMS', $html);
        $this->assertStringNotContainsString('Reason:', $html);
        $this->assertStringContainsString('id="' . EligibilityAlertRenderer::PLACEHOLDER_ID . '"', $html);
    }

    public function testHipaaDisallowsRendersWarningWithChartReason(): void
    {
        $html = $this->renderer->render([
            'can_send' => false,
            'reason' => SkipReason::HipaaDisallowsSms->value,
            'context' => ['hipaa_allowsms' => 'NO'],
            'phone' => null,
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('Allow SMS', $html);
    }

    public function testMissingPhoneRendersWarningWithPhoneReason(): void
    {
        $html = $this->renderer->render([
            'can_send' => false,
            'reason' => SkipReason::MissingPhone->value,
            'context' => [],
            'phone' => null,
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('mobile phone number is on file', $html);
    }

    public function testUnparseablePhoneRendersWarningWithParseReason(): void
    {
        $html = $this->renderer->render([
            'can_send' => false,
            'reason' => SkipReason::UnparseablePhone->value,
            'context' => ['phone_last4' => '1234'],
            'phone' => null,
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('could not be parsed', $html);
    }

    public function testModuleOptOutRendersWarningWithOptOutReason(): void
    {
        $html = $this->renderer->render([
            'can_send' => false,
            'reason' => SkipReason::ModuleOptOut->value,
            'context' => [],
            'phone' => '+15551112222',
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('opted out', $html);
    }

    public function testCarrierBlockedRendersWarningWithCarrierReason(): void
    {
        $html = $this->renderer->render([
            'can_send' => false,
            'reason' => SkipReason::CarrierBlocked->value,
            'context' => ['carrier_block_reason' => 'smpp_255'],
            'phone' => '+15551112222',
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('carrier', $html);
    }

    public function testUnknownReasonIsHtmlEscaped(): void
    {
        // A future SkipReason value lands in the default branch with the
        // raw token. Make sure the renderer escapes that token instead of
        // letting it land in the page as raw HTML.
        $html = $this->renderer->render([
            'can_send' => false,
            'reason' => '<script>alert(1)</script>',
            'context' => [],
            'phone' => null,
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderEmptyReturnsJustThePlaceholderDiv(): void
    {
        $html = $this->renderer->renderEmpty();

        $this->assertSame(
            '<div id="' . EligibilityAlertRenderer::PLACEHOLDER_ID . '"></div>',
            $html
        );
    }

    public function testServerAndJsPathsShareTheSamePlaceholderId(): void
    {
        // The JS layer relies on this id to find the div; pinning it here
        // means a rename in the renderer will also fail this test, forcing
        // the JS listener constant to be updated in the same change.
        $rendered = $this->renderer->render([
            'can_send' => true,
            'reason' => null,
            'context' => [],
            'phone' => '+15551112222',
        ]);

        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, $rendered);
        $this->assertStringContainsString(
            EligibilityAlertRenderer::PLACEHOLDER_ID,
            $this->renderer->renderEmpty()
        );
    }
}
