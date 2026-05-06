<?php

/**
 * Mock MenuEvent for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Menu;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Minimal stand-in for OpenEMR's MenuEvent. See issue #118 and
 * tools/openemr/README.md.
 */
class MenuEvent extends Event
{
    public const MENU_UPDATE = 'menu.update';
    public const MENU_RESTRICT = 'menu.restrict';

    /**
     * @param array<int|string, mixed> $menu
     */
    public function __construct(private array $menu = [])
    {
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getMenu(): array
    {
        return $this->menu;
    }

    /**
     * @param array<int|string, mixed> $menu
     */
    public function setMenu(array $menu): void
    {
        $this->menu = $menu;
    }
}
