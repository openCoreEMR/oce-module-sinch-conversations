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
