<?php

/**
 * Background service entry point for appointment reminders
 *
 * This file is require_once'd by OpenEMR's background service system
 * (execute_background_services.php). It defines the function registered
 * in the background_services table, which delegates to
 * AppointmentReminderService::run().
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc.
 * @link      https://www.opencoreemr.com
 */

declare(strict_types=1);

/**
 * Run appointment reminders via the background service system
 *
 * Called by OpenEMR's execute_background_services.php after require_once'ing
 * this file. The function name matches the `function` column in the
 * background_services table row for 'oce_sinch_reminders'.
 */
function oce_sinch_run_appointment_reminders(): void
{
    $moduleDir = __DIR__;
    $fileroot = (string) ($GLOBALS['fileroot'] ?? '');
    if ($fileroot === '') {
        return;
    }

    // The module's openemr.bootstrap.php registers namespaces via the
    // ModulesClassLoader, but background services bypass that path.
    // Register namespaces directly so autoloading works.
    $classLoader = new \OpenEMR\Core\ModulesClassLoader($fileroot);
    $classLoader->registerNamespaceIfNotExists(
        'OpenCoreEMR\\Sinch\\Conversation\\',
        $moduleDir . '/src/Sinch/Conversation'
    );
    $classLoader->registerNamespaceIfNotExists(
        'OpenCoreEMR\\Modules\\SinchConversations\\',
        $moduleDir . '/src/Module'
    );

    $globalsBag = \OpenEMR\Core\OEGlobalsBag::getInstance();
    $descriptor = \OpenCoreEMR\Modules\SinchConversations\SinchModuleConfig::createConfigDescriptor();
    $configFactory = new \OpenCoreEMR\ModuleConfig\ConfigFactory($descriptor, $globalsBag);
    $accessor = $configFactory->createConfigAccessor();

    $config = new \OpenCoreEMR\Modules\SinchConversations\GlobalConfig($accessor, $configFactory);

    if (!$config->isEnabled()) {
        return;
    }

    $kernel = ($GLOBALS['kernel'] ?? null) instanceof \OpenEMR\Core\Kernel
        ? $GLOBALS['kernel']
        : new \OpenEMR\Core\Kernel();
    $bootstrap = new \OpenCoreEMR\Modules\SinchConversations\Bootstrap(
        $kernel->getEventDispatcher(),
        $kernel,
        $accessor,
        $configFactory
    );

    $service = $bootstrap->getAppointmentReminderService();
    $service->run();
}
