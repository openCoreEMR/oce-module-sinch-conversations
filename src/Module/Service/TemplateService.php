<?php

/**
 * Message Template Service
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class TemplateService
{
    private readonly SystemLogger $logger;

    public function __construct(private readonly GlobalConfig $config)
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Render a template with variables
     *
     * @param string $templateKey
     * @param array<string, string> $variables
     * @return string Rendered message
     * @throws ValidationException
     */
    public function render(string $templateKey, array $variables): string
    {
        $template = $this->getTemplate($templateKey);

        if (!$template) {
            throw new ValidationException("Template not found: {$templateKey}");
        }

        $this->validateVariables($templateKey, $variables);

        $body = $template['body'];

        foreach ($variables as $key => $value) {
            $body = str_replace("{{ {$key} }}", (string)$value, $body);
        }

        return $body;
    }

    /**
     * Validate required variables are present
     *
     * @param string $templateKey
     * @param array<string, string> $variables
     * @return bool
     * @throws ValidationException
     */
    public function validateVariables(string $templateKey, array $variables): bool
    {
        $template = $this->getTemplate($templateKey);

        if (!$template) {
            throw new ValidationException("Template not found: {$templateKey}");
        }

        $required = json_decode((string) $template['required_variables'], true) ?? [];

        foreach ($required as $var) {
            if (!isset($variables[$var]) || $variables[$var] === '') {
                throw new ValidationException("Required variable missing: {$var}");
            }
        }

        return true;
    }

    /**
     * Get template by key
     *
     * @param string $templateKey
     * @return array<string, mixed>|null
     */
    public function getTemplate(string $templateKey): ?array
    {
        $sql = "SELECT * FROM oce_sinch_message_templates
                WHERE template_key = ? AND active = TRUE";
        $result = QueryUtils::querySingleRow($sql, [$templateKey]);

        return $result ?: null;
    }

    /**
     * Get all templates
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllTemplates(): array
    {
        $sql = "SELECT * FROM oce_sinch_message_templates
                WHERE active = TRUE
                ORDER BY category, template_name";
        return QueryUtils::fetchRecords($sql, []);
    }

    /**
     * Get templates by category
     *
     * @param string $category
     * @return array<int, array<string, mixed>>
     */
    public function getTemplatesByCategory(string $category): array
    {
        $sql = "SELECT * FROM oce_sinch_message_templates
                WHERE category = ? AND active = TRUE
                ORDER BY template_name";
        return QueryUtils::fetchRecords($sql, [$category]);
    }

    /**
     * Check if template is approved for batch sending
     *
     * @param string $templateKey
     * @return bool
     */
    public function isBatchApproved(string $templateKey): bool
    {
        $template = $this->getTemplate($templateKey);

        if (!$template) {
            return false;
        }

        return in_array($template['communication_type'], ['batch', 'both'], true);
    }

    /**
     * Create or update a template
     *
     * @param array<string, mixed> $data
     * @return int Template ID
     * @throws ValidationException
     */
    public function saveTemplate(array $data): int
    {
        if (empty($data['template_key']) || empty($data['body'])) {
            throw new ValidationException("Template key and body are required");
        }

        $existing = $this->getTemplate($data['template_key']);

        if ($existing) {
            $sql = "UPDATE oce_sinch_message_templates
                    SET template_name = ?,
                        category = ?,
                        communication_type = ?,
                        body = ?,
                        required_variables = ?,
                        updated_at = NOW()
                    WHERE template_key = ?";

            QueryUtils::sqlStatementThrowException($sql, [
                $data['template_name'] ?? $data['template_key'],
                $data['category'] ?? 'general',
                $data['communication_type'] ?? 'individual',
                $data['body'],
                json_encode($data['required_variables'] ?? []),
                $data['template_key'],
            ]);

            return (int)$existing['id'];
        }

        $sql = "INSERT INTO oce_sinch_message_templates (
            template_key, template_name, category, communication_type,
            body, required_variables, compliance_confidence,
            sinch_approved, active, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        QueryUtils::sqlStatementThrowException($sql, [
            $data['template_key'],
            $data['template_name'] ?? $data['template_key'],
            $data['category'] ?? 'general',
            $data['communication_type'] ?? 'individual',
            $data['body'],
            json_encode($data['required_variables'] ?? []),
            $data['compliance_confidence'] ?? 95,
            $data['sinch_approved'] ?? true,
            $data['active'] ?? true,
        ]);

        $sql = "SELECT LAST_INSERT_ID() as id";
        $result = QueryUtils::querySingleRow($sql, []);
        return (int)($result['id'] ?? 0);
    }
}
