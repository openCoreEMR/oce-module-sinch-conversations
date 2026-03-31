<?php

/**
 * Guards module web entry points from access when module is not installed/enabled
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenCoreEMR\ModuleConfig\ConfigAccessorInterface;
use OpenCoreEMR\ModuleConfig\ConfigFactory;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevents access to module web endpoints when the module is not properly installed and enabled.
 *
 * This guard checks three conditions:
 * 1. Module is registered in OpenEMR's modules table
 * 2. Module is enabled in module management (mod_active = 1)
 * 3. Module's own 'enabled' global setting is turned on
 *
 * If any condition fails, returns a 404 response to avoid leaking information
 * about the module's presence.
 */
class ModuleAccessGuard
{
    /**
     * Check if the module is accessible and return a 404 response if not.
     *
     * @param string $moduleDirectory The module directory name (e.g., 'oce-module-sinch-conversations')
     * @param ConfigAccessorInterface|null $configAccessor Optional config accessor for testing
     * @param (callable(string): bool)|null $moduleActiveChecker Optional callable for testing
     * @return Response|null Returns 404 Response if access denied, null if access allowed
     */
    public static function check(
        string $moduleDirectory,
        ?ConfigAccessorInterface $configAccessor = null,
        ?callable $moduleActiveChecker = null
    ): ?Response {
        // Check module registration and enabled status in modules table
        $isActive = $moduleActiveChecker !== null
            ? $moduleActiveChecker($moduleDirectory)
            : self::isModuleActive($moduleDirectory);

        if (!$isActive) {
            return self::createNotFoundResponse();
        }

        // Check module's own enabled setting (respects env config mode)
        if ($configAccessor === null) {
            $factory = new ConfigFactory(
                SinchModuleConfig::createConfigDescriptor(),
                OEGlobalsBag::getInstance()
            );
            $configAccessor = $factory->createConfigAccessor();
        }
        if (!$configAccessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED, false)) {
            return self::createNotFoundResponse();
        }

        return null;
    }

    /**
     * Check if module is registered and active in OpenEMR's modules table
     */
    private static function isModuleActive(string $moduleDirectory): bool
    {
        try {
            $sql = "SELECT mod_active FROM modules WHERE mod_directory = ?";
            $result = QueryUtils::fetchSingleValue($sql, 'mod_active', [$moduleDirectory]);

            // Module not found or not active
            if ($result === null || $result === false) {
                return false;
            }

            // Check if active (mod_active = 1)
            return is_numeric($result) && (int) $result === 1;
        } catch (\Throwable) {
            // Database error - fail closed (deny access)
            return false;
        }
    }

    /**
     * Create a 404 Not Found response
     */
    private static function createNotFoundResponse(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND);
    }
}
