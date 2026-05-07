<?php

/**
 * Unit tests for TemplateSyncService
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
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateSyncService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TemplateSyncServiceTest extends TestCase
{
    private GlobalConfig $config;
    private ConversationApiClient&MockObject $apiClient;
    private TemplateSyncService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->config = new GlobalConfig(new MockGlobalsAccessor([]), new MockConfigFactory());
        $this->apiClient = $this->createMock(ConversationApiClient::class);
        $this->service = new TemplateSyncService($this->config, $this->apiClient);
    }

    /**
     * A failing template in the middle of the list must not abort the whole sync.
     * Regression test for issue #121.
     */
    public function testSyncContinuesAfterTemplateFailure(): void
    {
        $this->apiClient->method('listTemplates')->willReturn([]);

        // First create throws; subsequent ones succeed. Without the fix
        // (the `break` after first failure), only one createTemplate call
        // would be observed and `failed` would be 1 with all later templates
        // counted as 0/0/0.
        $createCalls = 0;
        $this->apiClient->method('createTemplate')
            ->willReturnCallback(function (array $template) use (&$createCalls): array {
                $createCalls++;
                if ($createCalls === 1) {
                    throw new ApiException('Translation must specify a message.', 400);
                }
                return ['id' => 'sinch-tmpl-' . $createCalls];
            });

        $results = $this->service->syncAllTemplates();

        $this->assertGreaterThan(1, $results['total'], 'fixture should have multiple templates');
        $this->assertSame(1, $results['failed'], 'only the first template should be marked failed');
        $this->assertSame($results['total'], $createCalls, 'every template after the failure was still attempted');

        $this->assertCount(1, $results['errors']);
        $error = $results['errors'][0];
        $this->assertArrayHasKey('template_key', $error);
        $this->assertArrayHasKey('errorId', $error);
        $this->assertSame(
            'Translation must specify a message.',
            $error['error'],
            'underlying exception message must be surfaced for the UI'
        );
    }

    /**
     * Non-API exceptions (e.g. internal TypeError) must NOT leak their message
     * into the UI — only `ApiException` messages, which originate from the
     * Sinch API and are safe operator-facing validation errors, are surfaced
     * verbatim. Everything else gets a generic message + errorId.
     */
    /**
     * Re-syncing the same templates after they exist in Sinch with their
     * content-versioned descriptions must skip every one — no createTemplate
     * calls. Regression guard for issue #124: the description match must
     * line up with what we previously sent.
     */
    public function testResyncWithUnchangedContentSkipsAll(): void
    {
        $createCalls = 0;
        $sentDescriptions = [];
        $this->apiClient->method('listTemplates')->willReturn([]);
        $this->apiClient->method('createTemplate')
            ->willReturnCallback(function (array $template) use (&$createCalls, &$sentDescriptions): array {
                $sentDescriptions[] = $template['description'];
                $createCalls++;
                return ['id' => 'sinch-tmpl-' . $createCalls];
            });

        $first = $this->service->syncAllTemplates();
        $this->assertSame($first['total'], $createCalls, 'first sync should create every template');

        $apiClient2 = $this->createMock(ConversationApiClient::class);
        $existing = [];
        foreach ($sentDescriptions as $i => $desc) {
            $existing[] = ['id' => 'sinch-tmpl-' . ($i + 1), 'description' => $desc];
        }
        $apiClient2->method('listTemplates')->willReturn($existing);
        $apiClient2->expects($this->never())->method('createTemplate');

        $service2 = new TemplateSyncService($this->config, $apiClient2);
        $second = $service2->syncAllTemplates();

        $this->assertSame($second['total'], $second['skipped'], 'every template should be skipped on re-sync');
        $this->assertSame(0, $second['failed']);
    }

    /**
     * If a Sinch template exists under the legacy bare-name description
     * (from a pre-versioning sync), the new code must NOT adopt it — first
     * sync after upgrade creates fresh content-versioned templates.
     */
    public function testLegacyBareDescriptionIsIgnored(): void
    {
        $configPath = dirname(__DIR__, 3) . '/config/templates.php';
        $definitions = require $configPath;
        $legacyExisting = [];
        foreach ($definitions as $i => $def) {
            $legacyExisting[] = [
                'id' => 'legacy-' . ($i + 1),
                'description' => $def['description'] ?? $def['template_name'],
            ];
        }

        $this->apiClient->method('listTemplates')->willReturn($legacyExisting);
        $createCalls = 0;
        $this->apiClient->method('createTemplate')
            ->willReturnCallback(function (array $template) use (&$createCalls): array {
                $createCalls++;
                $this->assertStringContainsString('@', $template['description']);
                return ['id' => 'sinch-tmpl-' . $createCalls];
            });

        $results = $this->service->syncAllTemplates();

        $this->assertSame($results['total'], $createCalls, 'legacy descriptions must not satisfy the match');
        $this->assertSame(0, $results['skipped']);
    }

    /**
     * A stale versioned description in Sinch (different hash) must be treated
     * as a miss and trigger creation of a new Sinch template — this is the
     * core multi-tenant safety property: a body change actually reaches Sinch.
     */
    public function testStaleVersionedDescriptionTriggersNewCreation(): void
    {
        $configPath = dirname(__DIR__, 3) . '/config/templates.php';
        $definitions = require $configPath;
        $stale = [];
        foreach ($definitions as $i => $def) {
            $stale[] = [
                'id' => 'stale-' . ($i + 1),
                'description' => $def['template_key'] . '@deadbeef',
            ];
        }

        $this->apiClient->method('listTemplates')->willReturn($stale);
        $createCalls = 0;
        $this->apiClient->method('createTemplate')
            ->willReturnCallback(function (array $template) use (&$createCalls): array {
                $createCalls++;
                $this->assertStringEndsNotWith('@deadbeef', $template['description']);
                return ['id' => 'sinch-tmpl-' . $createCalls];
            });

        $results = $this->service->syncAllTemplates();

        $this->assertSame($results['total'], $createCalls);
        $this->assertSame(0, $results['skipped']);
    }

    public function testNonApiExceptionDoesNotLeakMessageToUi(): void
    {
        $this->apiClient->method('listTemplates')->willReturn([]);
        $this->apiClient->method('createTemplate')
            ->willThrowException(new RuntimeException('/var/www/html/secret/path/to/internal.php on line 42'));

        $results = $this->service->syncAllTemplates();

        $error = $results['errors'][0];
        $this->assertStringNotContainsString('/var/www/html', $error['error']);
        $this->assertStringContainsString($error['errorId'], $error['error']);
    }
}
