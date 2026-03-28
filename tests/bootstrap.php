<?php

/**
 * PHPUnit Bootstrap File
 *
 * This file sets up the test environment and loads necessary dependencies.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Suppress error_log output during tests by redirecting to null
ini_set('error_log', '/dev/null');

// Load mock classes before anything else to prevent "class not found" errors
require_once __DIR__ . '/Mocks/MockKernel.php';
require_once __DIR__ . '/Mocks/MockTwigContainer.php';
require_once __DIR__ . '/Mocks/MockSystemLogger.php';
require_once __DIR__ . '/Mocks/MockQueryUtils.php';
require_once __DIR__ . '/Mocks/MockCryptoGen.php';
require_once __DIR__ . '/Mocks/MockCsrfUtils.php';

// Define OpenEMR global functions used in controllers and Bootstrap
if (!function_exists('xl')) {
    /**
     * Mock translation function - just returns the input string
     */
    function xl(string $text): string
    {
        return $text;
    }
}

if (!function_exists('xlt')) {
    /**
     * Mock translation function - just returns the input string
     */
    function xlt(string $text): string
    {
        return $text;
    }
}

if (!function_exists('text')) {
    /**
     * Mock text sanitization function - just returns the input string
     */
    function text(string $text): string
    {
        return $text;
    }
}

if (!function_exists('xlj')) {
    /**
     * Mock JSON translation function - returns JSON-encoded string
     */
    function xlj(string $text): string
    {
        return json_encode($text);
    }
}

if (!function_exists('attr')) {
    /**
     * Mock attribute sanitization function - just returns the input string
     */
    function attr(string $text): string
    {
        return $text;
    }
}

// Define constants used in tests
if (!defined('DIRECTORY_SEPARATOR')) {
    define('DIRECTORY_SEPARATOR', '/');
}
