<?php

/**
 * Trait for surfacing chained exception detail in CLI verbose output
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Write chained exception detail to verbose CLI output
 *
 * After #52 switched API clients from message concatenation to exception
 * chaining ($previous), the underlying HTTP failure detail is no longer
 * embedded in the message string. This trait surfaces the full chain in
 * verbose/debug output so troubleshooting doesn't regress.
 */
trait WritesExceptionChain
{
    /**
     * Write chained exception messages and trace to verbose output
     *
     * The primary $io->error() call provides the user-facing message.
     * This method adds diagnostic detail visible only at -v or higher.
     */
    private function writeExceptionChain(
        SymfonyStyle $io,
        OutputInterface $output,
        \Throwable $exception
    ): void {
        if (!$output->isVerbose()) {
            return;
        }

        $previous = $exception->getPrevious();
        while ($previous instanceof \Throwable) {
            $io->text(sprintf(
                'Caused by %s: %s',
                $previous::class,
                $previous->getMessage()
            ));
            $previous = $previous->getPrevious();
        }

        if ($output->isVeryVerbose()) {
            $io->text($exception->getTraceAsString());
        }
    }

    /**
     * Build a message string that includes the root cause from chained exceptions
     *
     * Walk the full chain to reach the deepest exception, since the root
     * cause (e.g. a cURL timeout) is often more useful than the intermediate
     * wrapper (e.g. a Guzzle exception). Use in result detail strings and
     * log output where the cause should be visible regardless of verbosity.
     */
    private function messageWithCause(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        $root = $exception->getPrevious();

        while ($root instanceof \Throwable && $root->getPrevious() instanceof \Throwable) {
            $root = $root->getPrevious();
        }

        if ($root instanceof \Throwable) {
            $message .= ' (caused by: ' . $root->getMessage() . ')';
        }

        return $message;
    }
}
