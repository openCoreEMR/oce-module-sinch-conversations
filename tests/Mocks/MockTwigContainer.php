<?php

/**
 * Mock TwigContainer for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Twig;

use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Mock TwigContainer for testing - minimal implementation
 */
class TwigContainer
{
    private Environment $twig;

    public function __construct(string $templatePath = '', $kernel = null)
    {
        // Create a minimal Twig environment for testing
        $loader = new ArrayLoader([
            'test.html.twig' => 'Test template',
        ]);
        $this->twig = new Environment($loader);
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }
}
