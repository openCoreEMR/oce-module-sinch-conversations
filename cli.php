#!/usr/bin/env php
<?php

/**
 * Sinch Conversations Module CLI
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/vendor/autoload.php';

use OpenCoreEMR\Modules\SinchConversations\Command\AppListCommand;
use OpenCoreEMR\Modules\SinchConversations\Command\InspectCommand;
use OpenCoreEMR\Modules\SinchConversations\Command\WebhookCreateCommand;
use OpenCoreEMR\Modules\SinchConversations\Command\WebhookListCommand;
use Symfony\Component\Console\Application;

$application = new Application('Sinch Conversations CLI', '1.0.0');

// Register commands
$application->add(new InspectCommand());
$application->add(new AppListCommand());
$application->add(new WebhookListCommand());
$application->add(new WebhookCreateCommand());

$application->run();
