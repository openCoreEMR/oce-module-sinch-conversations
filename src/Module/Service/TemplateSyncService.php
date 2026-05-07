<?php

/**
 * Template Sync Service - Syncs local templates to Sinch
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\ErrorId;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class TemplateSyncService
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ConversationApiClient $apiClient
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Sync all templates from config file to Sinch and local database
     *
     * @return array<string, mixed> Sync results with counts
     * @throws \Throwable
     */
    public function syncAllTemplates(): array
    {
        $this->logger->info('Starting template sync to Sinch');

        $templateDefinitions = $this->loadTemplateDefinitions();
        $results = [
            'total' => count($templateDefinitions),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Get existing templates from Sinch to check for duplicates
        try {
            $existingTemplates = $this->apiClient->listTemplates();
            $existingByDescription = [];
            foreach ($existingTemplates as $template) {
                $desc = $template['description'] ?? '';
                if (!empty($desc)) {
                    $existingByDescription[$desc] = $template;
                }
            }
            $this->logger->debug('Found existing templates in Sinch', [
                'count' => count($existingTemplates),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not list existing templates, will attempt to create all', [
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            $existingByDescription = [];
        }

        foreach ($templateDefinitions as $template) {
            try {
                // Match Sinch templates by a content-versioned description
                // (`template_key@<hash>`) so a body change produces a new
                // Sinch template instead of silently reusing the old one.
                // Legacy bare-name descriptions from pre-versioning syncs
                // are intentionally ignored — first sync after upgrade
                // creates fresh versioned entries.
                $template['description'] = $this->versionedDescription($template);

                if (isset($existingByDescription[$template['description']])) {
                    $sinchTemplate = $existingByDescription[$template['description']];
                    $sinchTemplateId = $sinchTemplate['id'] ?? null;

                    $this->logger->debug('Template already exists in Sinch', [
                        'templateKey' => $template['template_key'],
                        'sinchId' => $sinchTemplateId,
                    ]);

                    if ($sinchTemplateId) {
                        $this->saveTemplateLocally($template, $sinchTemplateId);
                        $results['skipped']++;
                        continue;
                    }
                }

                $this->syncTemplate($template);

                // Check if template already existed locally
                $existing = $this->getLocalTemplate($template['template_key']);
                if ($existing && !empty($existing['sinch_template_id'])) {
                    $results['updated']++;
                } else {
                    $results['created']++;
                }
            } catch (\Throwable $e) {
                $errorId = ErrorId::generate();
                $results['failed']++;
                // Surface upstream API messages so operators can see the validation
                // reason (e.g. "Translation must specify a message"), but for any
                // other exception class show a generic message + errorId — internal
                // exceptions can leak file paths or other internals.
                $uiMessage = $e instanceof ApiException
                    ? $e->getMessage()
                    : "Internal error (ref: $errorId)";
                $results['errors'][] = [
                    'template_key' => $template['template_key'],
                    'errorId' => $errorId,
                    'error' => $uiMessage,
                ];
                $this->logger->error('Failed to sync template', [
                    'templateKey' => $template['template_key'],
                    'errorId' => $errorId,
                    'exception' => ExceptionContext::fromThrowable($e),
                ]);
            }
        }

        $this->logger->info('Template sync completed', $results);
        return $results;
    }

    /**
     * Sync a single template to Sinch and local database
     *
     * @param array<string, mixed> $template
     * @return void
     * @throws ApiException
     */
    public function syncTemplate(array $template): void
    {
        $this->logger->debug('Syncing template', ['templateKey' => $template['template_key']]);

        // First, create the template in Sinch
        $sinchResponse = $this->apiClient->createTemplate($template);
        $sinchTemplateId = $sinchResponse['id'] ?? null;

        if (empty($sinchTemplateId)) {
            throw new ApiException("Failed to get template ID from Sinch response");
        }

        $this->logger->debug(
            "Template created in Sinch",
            ['template_key' => $template['template_key'], 'sinch_id' => $sinchTemplateId]
        );

        // Then save or update it in the local database
        $this->saveTemplateLocally($template, $sinchTemplateId);
    }

    /**
     * Build a content-versioned Sinch description for a template definition.
     *
     * Format: `{template_key}@{hash8}`. The hash covers the fields that
     * affect what Sinch (and downstream carriers) actually need to re-approve:
     * body, required variables, category, and communication type. A change
     * to any of these produces a new Sinch template rather than silently
     * reusing the old one.
     *
     * @param array<string, mixed> $template
     */
    private function versionedDescription(array $template): string
    {
        $variables = $template['required_variables'] ?? [];
        if (is_array($variables)) {
            sort($variables);
        }

        $canonical = json_encode([
            'body' => $template['body'] ?? '',
            'required_variables' => $variables,
            'category' => $template['category'] ?? '',
            'communication_type' => $template['communication_type'] ?? '',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $hash = substr(hash('sha256', (string) $canonical), 0, 8);

        return $template['template_key'] . '@' . $hash;
    }

    /**
     * Load template definitions from config file
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadTemplateDefinitions(): array
    {
        $configPath = dirname(__DIR__, 3) . '/config/templates.php';

        if (!file_exists($configPath)) {
            throw new \RuntimeException("Template configuration file not found: {$configPath}");
        }

        $templates = require $configPath;

        if (!is_array($templates)) {
            throw new \RuntimeException("Template configuration must return an array");
        }

        return $templates;
    }

    /**
     * Get template from local database
     *
     * @param string $templateKey
     * @return array<string, mixed>|null
     */
    private function getLocalTemplate(string $templateKey): ?array
    {
        $sql = "SELECT * FROM oce_sinch_message_templates WHERE template_key = ?";
        $result = QueryUtils::querySingleRow($sql, [$templateKey]);
        return $result ?: null;
    }

    /**
     * Save template to local database
     *
     * @param array<string, mixed> $template
     * @param string $sinchTemplateId
     * @return void
     */
    private function saveTemplateLocally(array $template, string $sinchTemplateId): void
    {
        $existing = $this->getLocalTemplate($template['template_key']);

        if ($existing) {
            // Update existing template
            $sql = "UPDATE oce_sinch_message_templates
                    SET template_name = ?,
                        category = ?,
                        communication_type = ?,
                        body = ?,
                        required_variables = ?,
                        compliance_confidence = ?,
                        sinch_approved = ?,
                        sinch_template_id = ?,
                        active = ?,
                        updated_at = NOW()
                    WHERE template_key = ?";

            QueryUtils::sqlStatementThrowException($sql, [
                $template['template_name'],
                $template['category'],
                $template['communication_type'],
                $template['body'],
                json_encode($template['required_variables']),
                $template['compliance_confidence'] ?? 95,
                $template['sinch_approved'] ?? true,
                $sinchTemplateId,
                $template['active'] ?? true,
                $template['template_key'],
            ]);

            $this->logger->debug('Updated local template', ['templateKey' => $template['template_key']]);
        } else {
            // Insert new template
            $sql = "INSERT INTO oce_sinch_message_templates (
                        template_key, template_name, category, communication_type,
                        body, required_variables, compliance_confidence,
                        sinch_approved, sinch_template_id, active,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            QueryUtils::sqlStatementThrowException($sql, [
                $template['template_key'],
                $template['template_name'],
                $template['category'],
                $template['communication_type'],
                $template['body'],
                json_encode($template['required_variables']),
                $template['compliance_confidence'] ?? 95,
                $template['sinch_approved'] ?? true,
                $sinchTemplateId,
                $template['active'] ?? true,
            ]);

            $this->logger->debug('Inserted local template', ['templateKey' => $template['template_key']]);
        }
    }
}
