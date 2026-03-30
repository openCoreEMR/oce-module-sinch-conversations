<?php

/**
 * Messaging channel types
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

enum Channel: string
{
    case SMS = 'SMS';
    case WHATSAPP = 'WHATSAPP';
    case RCS = 'RCS';
}
