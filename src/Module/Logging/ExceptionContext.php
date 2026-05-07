<?php

/**
 * Helper for serialising Throwables into PSR-3 log context arrays.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Logging;

use Throwable;

final class ExceptionContext
{
    /**
     * Convert a Throwable (and its previous-exception chain) to a JSON-serialisable array.
     *
     * OpenEMR's SystemLogger calls json_encode() on object context values, and
     * Throwables expose no public properties — so passing $e directly results
     * in "{}" in the log. This helper produces an explicit, useful shape.
     *
     * The previous-exception chain matters: services often re-throw a domain
     * exception wrapping the underlying cause (e.g. ValidationException wrapping
     * a Sinch ApiException). Without recursing, the log loses the real reason.
     *
     * @return array{class: class-string, message: string, file: string, trace: string, previous?: array<string, mixed>}
     */
    public static function fromThrowable(Throwable $e): array
    {
        $context = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        $previous = $e->getPrevious();
        if ($previous instanceof Throwable) {
            $context['previous'] = self::fromThrowable($previous);
        }

        return $context;
    }
}
