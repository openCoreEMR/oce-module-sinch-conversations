<?php

/**
 * Unit tests for TemplateService
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
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;

class TemplateServiceTest extends TestCase
{
    private TemplateService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $config = new GlobalConfig(new MockGlobalsAccessor([]));
        $this->service = new TemplateService($config);
    }

    // --- getTemplate ---

    public function testGetTemplateReturnsTemplate(): void
    {
        $this->mockTemplate('greeting', 'Hello {{ name }}', '["name"]');

        $result = $this->service->getTemplate('greeting');

        $this->assertNotNull($result);
        $this->assertEquals('greeting', $result['template_key']);
    }

    public function testGetTemplateReturnsNullWhenNotFound(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE",
            ['nonexistent'],
            []
        );

        $this->assertNull($this->service->getTemplate('nonexistent'));
    }

    // --- render ---

    public function testRenderSubstitutesVariables(): void
    {
        $this->mockTemplate('greeting', 'Hello {{ name }}, welcome to {{ clinic }}', '[]');

        $result = $this->service->render('greeting', ['name' => 'John', 'clinic' => 'Test Clinic']);

        $this->assertEquals('Hello John, welcome to Test Clinic', $result);
    }

    public function testRenderThrowsForMissingTemplate(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE",
            ['missing'],
            []
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Template not found: missing');

        $this->service->render('missing', []);
    }

    // --- validateVariables ---

    public function testValidateVariablesPassesWhenAllPresent(): void
    {
        $this->mockTemplate('test', 'Hello {{ name }}', '["name"]');

        $this->assertTrue($this->service->validateVariables('test', ['name' => 'John']));
    }

    public function testValidateVariablesThrowsForMissingRequired(): void
    {
        $this->mockTemplate('test', 'Hello {{ name }}', '["name"]');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Required variable missing: name');

        $this->service->validateVariables('test', []);
    }

    public function testValidateVariablesThrowsForEmptyRequired(): void
    {
        $this->mockTemplate('test', 'Hello {{ name }}', '["name"]');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Required variable missing: name');

        $this->service->validateVariables('test', ['name' => '']);
    }

    public function testValidateVariablesThrowsForMissingTemplate(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE",
            ['missing'],
            []
        );

        $this->expectException(ValidationException::class);
        $this->service->validateVariables('missing', []);
    }

    // --- getAllTemplates ---

    public function testGetAllTemplatesReturnsRecords(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE active = TRUE
                ORDER BY category, template_name",
            [],
            [
                ['template_key' => 'a', 'template_name' => 'Template A'],
                ['template_key' => 'b', 'template_name' => 'Template B'],
            ]
        );

        $result = $this->service->getAllTemplates();

        $this->assertCount(2, $result);
    }

    // --- getTemplatesByCategory ---

    public function testGetTemplatesByCategoryFilters(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE category = ? AND active = TRUE
                ORDER BY template_name",
            ['consent'],
            [['template_key' => 'opt_in', 'category' => 'consent']]
        );

        $result = $this->service->getTemplatesByCategory('consent');

        $this->assertCount(1, $result);
    }

    // --- isBatchApproved ---

    public function testIsBatchApprovedReturnsTrueForBatch(): void
    {
        $this->mockTemplate('batch_t', 'Hello', '[]', 'batch');

        $this->assertTrue($this->service->isBatchApproved('batch_t'));
    }

    public function testIsBatchApprovedReturnsTrueForBoth(): void
    {
        $this->mockTemplate('both_t', 'Hello', '[]', 'both');

        $this->assertTrue($this->service->isBatchApproved('both_t'));
    }

    public function testIsBatchApprovedReturnsFalseForIndividual(): void
    {
        $this->mockTemplate('ind_t', 'Hello', '[]', 'individual');

        $this->assertFalse($this->service->isBatchApproved('ind_t'));
    }

    public function testIsBatchApprovedReturnsFalseForMissing(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE",
            ['missing'],
            []
        );

        $this->assertFalse($this->service->isBatchApproved('missing'));
    }

    // --- saveTemplate ---

    public function testSaveTemplateCreatesNew(): void
    {
        // Template doesn't exist yet
        QueryUtils::queueMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE",
            ['new_template'],
            []
        );

        // LAST_INSERT_ID
        QueryUtils::setMockResult(
            "SELECT LAST_INSERT_ID() as id",
            [],
            [['id' => 99]]
        );

        $id = $this->service->saveTemplate([
            'template_key' => 'new_template',
            'body' => 'Hello {{ name }}',
            'template_name' => 'New Template',
            'category' => 'general',
        ]);

        $this->assertEquals(99, $id);

        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO'));
        $this->assertNotEmpty($insertQueries);
    }

    public function testSaveTemplateUpdatesExisting(): void
    {
        // Template exists
        QueryUtils::queueMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE",
            ['existing'],
            [['id' => 5, 'template_key' => 'existing', 'body' => 'old body']]
        );

        $id = $this->service->saveTemplate([
            'template_key' => 'existing',
            'body' => 'updated body',
        ]);

        $this->assertEquals(5, $id);

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE'));
        $this->assertNotEmpty($updateQueries);
    }

    public function testSaveTemplateThrowsForMissingKey(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Template key and body are required');

        $this->service->saveTemplate(['body' => 'Hello']);
    }

    public function testSaveTemplateThrowsForMissingBody(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->saveTemplate(['template_key' => 'test']);
    }

    // --- getAppointmentReminderTemplateKey ---

    public function testAppointmentReminderTemplateKeyReturnsPortalWhenEnabled(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([
            'portal_onsite_two_enable' => true,
        ]));
        $service = new TemplateService($config);

        $this->assertEquals('appointment_reminder_portal', $service->getAppointmentReminderTemplateKey());
    }

    public function testAppointmentReminderTemplateKeyReturnsNoPortalWhenDisabled(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([
            'portal_onsite_two_enable' => false,
        ]));
        $service = new TemplateService($config);

        $this->assertEquals('appointment_reminder_no_portal', $service->getAppointmentReminderTemplateKey());
    }

    public function testAppointmentReminderTemplateKeyDefaultsToNoPortal(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([]));
        $service = new TemplateService($config);

        $this->assertEquals('appointment_reminder_no_portal', $service->getAppointmentReminderTemplateKey());
    }

    // --- Helpers ---

    private function mockTemplate(
        string $key,
        string $body,
        string $requiredVars,
        string $communicationType = 'individual'
    ): void {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE",
            [$key],
            [[
                'id' => 1,
                'template_key' => $key,
                'template_name' => $key,
                'body' => $body,
                'required_variables' => $requiredVars,
                'communication_type' => $communicationType,
                'category' => 'general',
                'active' => true,
            ]]
        );
    }
}
