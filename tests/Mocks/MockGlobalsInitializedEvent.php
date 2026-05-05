<?php

/**
 * Mock GlobalsInitializedEvent for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Events\Globals;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Minimal stand-in for OpenEMR's GlobalsInitializedEvent. Real implementation
 * lives in tools/openemr/vendor/openemr/openemr/src/Events/Globals/. See
 * issue #118 and tools/openemr/README.md for why OpenEMR isn't on the runtime
 * autoloader.
 */
class GlobalsInitializedEvent extends Event
{
    public const EVENT_HANDLE = 'globals.initialized';

    private ?object $globalsService;

    public function __construct(?object $globalsService = null)
    {
        $this->globalsService = $globalsService;
    }

    public function getGlobalsService(): ?object
    {
        return $this->globalsService;
    }
}
