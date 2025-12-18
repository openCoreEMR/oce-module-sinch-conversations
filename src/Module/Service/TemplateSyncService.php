<?php

/**
 * Template Sync Service - Syncs local templates to Sinch
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
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
        $this->logger->info("Starting template sync to Sinch");

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
            $this->logger->debug(
                "Found " . count($existingTemplates) . " existing templates in Sinch"
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Could not list existing templates, will attempt to create all: " . $e->getMessage()
            );
            $existingByDescription = [];
        }

        foreach ($templateDefinitions as $template) {
            try {
                $description = $template['description'] ?? $template['template_name'];

                // Check if template already exists in Sinch
                if (isset($existingByDescription[$description])) {
                    $sinchTemplate = $existingByDescription[$description];
                    $sinchTemplateId = $sinchTemplate['id'] ?? null;

                    $this->logger->debug(
                        "Template already exists in Sinch: {$template['template_key']}",
                        ['sinch_id' => $sinchTemplateId]
                    );

                    // Save/update locally with existing Sinch ID
                    if ($sinchTemplateId) {
                        $this->saveTemplateLocally($template, $sinchTemplateId);
                        $results['skipped']++;
                        continue;
                    }
                }

                // Template doesn't exist, sync it
                $this->syncTemplate($template);

                // Check if template already existed locally
                $existing = $this->getLocalTemplate($template['template_key']);
                if ($existing && !empty($existing['sinch_template_id'])) {
                    $results['updated']++;
                } else {
                    $results['created']++;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'template_key' => $template['template_key'],
                    'error' => $e->getMessage(),
                ];
                $this->logger->error(
                    "Failed to sync template: {$template['template_key']}",
                    ['error' => $e->getMessage()]
                );

                // Stop on first failure
                $this->logger->warning("Stopping template sync due to failure");
                break;
            }
        }

        $this->logger->info("Template sync completed", $results);
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
        $this->logger->debug("Syncing template: {$template['template_key']}");

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

            $this->logger->debug("Updated local template: {$template['template_key']}");
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

            $this->logger->debug("Inserted local template: {$template['template_key']}");
        }
    }
}
