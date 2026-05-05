<?php

/**
 * Mock GlobalSetting for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Globals;

/**
 * Minimal stand-in for OpenEMR's GlobalSetting — preserves the constants and
 * format() shape exercised by GlobalsRegistrar and module tests. See issue #118
 * and tools/openemr/README.md.
 */
class GlobalSetting
{
    public const DATA_TYPE_BOOL = 'bool';
    public const DATA_TYPE_TEXT = 'text';
    public const DATA_TYPE_PASS = 'pass';
    public const DATA_TYPE_ENCRYPTED = 'encrypted';
    public const DATA_TYPE_NUMBER = 'num';
    public const DATA_TYPE_HTML_DISPLAY_SECTION = 'html_display_section';
    public const DATA_TYPE_MULTI_SORTED_LIST_SELECTOR = 'multi_sorted_list_selector';

    public const DATA_TYPE_OPTION_LIST_ID = 'list_id';
    public const DATA_TYPE_OPTION_RENDER_CALLBACK = 'render_callback';

    public const DATA_TYPES_WITH_OPTIONS = [
        self::DATA_TYPE_MULTI_SORTED_LIST_SELECTOR,
        self::DATA_TYPE_HTML_DISPLAY_SECTION,
    ];

    public const DATA_TYPE_FIELD_OPTIONS_SUPPORTED = [
        self::DATA_TYPE_MULTI_SORTED_LIST_SELECTOR => [self::DATA_TYPE_OPTION_LIST_ID],
        self::DATA_TYPE_HTML_DISPLAY_SECTION => [self::DATA_TYPE_OPTION_RENDER_CALLBACK],
    ];

    /** @var array<string, mixed> */
    protected array $fieldOptions = [];

    public function __construct(
        protected mixed $label,
        protected string $dataType,
        protected mixed $default,
        protected mixed $description,
        protected bool $isUserSetting = false,
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function format(): array
    {
        $result = [$this->label, $this->dataType, $this->default, $this->description];
        if (count($this->fieldOptions) > 0) {
            $result[] = $this->fieldOptions;
        }
        return $result;
    }

    public function isUserSetting(): bool
    {
        return $this->isUserSetting;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldOptions(): array
    {
        return $this->fieldOptions;
    }

    public function addFieldOption(string $key, mixed $option): void
    {
        if (!$this->dataTypeSupportsOptions($this->dataType)) {
            throw new \InvalidArgumentException('Data type does not support field options');
        }
        if (!$this->dataTypeSupportsOptionKey($this->dataType, $key)) {
            throw new \InvalidArgumentException('Data type does not support field option key ' . $key);
        }
        $this->fieldOptions[$key] = $option;
    }

    public function dataTypeSupportsOptions(string $dataType): bool
    {
        return in_array($dataType, self::DATA_TYPES_WITH_OPTIONS, true);
    }

    public function dataTypeSupportsOptionKey(string $dataType, string $key): bool
    {
        if (!$this->dataTypeSupportsOptions($dataType)) {
            return false;
        }
        return in_array($key, self::DATA_TYPE_FIELD_OPTIONS_SUPPORTED[$dataType], true);
    }
}
