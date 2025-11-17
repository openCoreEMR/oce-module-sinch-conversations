<?php

/**
 * Initializes the OpenCoreEmr Sinch Conversations Module
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

/**
 * @var \OpenEMR\Core\ModulesClassLoader $classLoader Injected by the OpenEMR module loader
 */
// Register Sinch Conversation API client namespace
$classLoader->registerNamespaceIfNotExists(
    'OpenCoreEMR\\Sinch\\Conversation\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Sinch' . DIRECTORY_SEPARATOR . 'Conversation'
);

// Register module namespace
$classLoader->registerNamespaceIfNotExists(
    'OpenCoreEMR\\Modules\\SinchConversations\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Module'
);

/**
 * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
 *      Injected by the OpenEMR module loader
 */
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
