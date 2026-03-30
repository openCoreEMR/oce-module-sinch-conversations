#!/usr/bin/env php
<?php

/**
 * CLI tool to manage OpenEMR modules
 *
 * Usage:
 *   php bin/install-module.php module:list
 *   php bin/install-module.php module:install-enable oce-module-sinch-conversations
 *   php bin/install-module.php module:register oce-module-sinch-conversations
 *   php bin/install-module.php module:install oce-module-sinch-conversations
 *   php bin/install-module.php module:enable oce-module-sinch-conversations
 *   php bin/install-module.php module:disable oce-module-sinch-conversations
 *   php bin/install-module.php module:unregister oce-module-sinch-conversations
 *
 * @package   OpenEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "This script can only be run from the command line.\n";
    exit(1);
}

// Find and load autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    fwrite(STDERR, "Error: Could not find Composer autoloader.\n");
    fwrite(STDERR, "Run 'composer install' first.\n");
    exit(1);
}

use OpenCoreEMR\Modules\SinchConversations\Console\Command\ModuleDisableCommand;
use OpenCoreEMR\Modules\SinchConversations\Console\Command\ModuleEnableCommand;
use OpenCoreEMR\Modules\SinchConversations\Console\Command\ModuleInstallCommand;
use OpenCoreEMR\Modules\SinchConversations\Console\Command\ModuleInstallEnableCommand;
use OpenCoreEMR\Modules\SinchConversations\Console\Command\ModuleListCommand;
use OpenCoreEMR\Modules\SinchConversations\Console\Command\ModuleRegisterCommand;
use OpenCoreEMR\Modules\SinchConversations\Console\Command\ModuleUnregisterCommand;
use Symfony\Component\Console\Application;

$application = new Application('OpenEMR Module Installer', '1.0.0');

$application->add(new ModuleListCommand());
$application->add(new ModuleRegisterCommand());
$application->add(new ModuleInstallCommand());
$application->add(new ModuleEnableCommand());
$application->add(new ModuleDisableCommand());
$application->add(new ModuleUnregisterCommand());
$application->add(new ModuleInstallEnableCommand());

$application->run();
