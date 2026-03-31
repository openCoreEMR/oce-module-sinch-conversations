<?php

/**
 * Mock ConfigFactory for testing
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc. <https://www.opencoreemr.com>
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Mocks;

use OpenCoreEMR\ModuleConfig\ConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\SinchModuleConfig;
use Symfony\Component\HttpFoundation\ParameterBag;

class MockConfigFactory extends ConfigFactory
{
    public function __construct()
    {
        parent::__construct(
            SinchModuleConfig::createConfigDescriptor(),
            new ParameterBag()
        );
    }
}
