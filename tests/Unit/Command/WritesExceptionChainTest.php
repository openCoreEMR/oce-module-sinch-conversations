<?php

/**
 * Unit tests for WritesExceptionChain trait
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Command;

use OpenCoreEMR\Modules\SinchConversations\Command\WritesExceptionChain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class WritesExceptionChainTest extends TestCase
{
    private function createSubject(): object
    {
        return new class () {
            use WritesExceptionChain {
                writeExceptionChain as public;
                messageWithCause as public;
            }
        };
    }

    private function makeIo(OutputInterface $output): SymfonyStyle
    {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        return new SymfonyStyle($input, $output);
    }

    // --- messageWithCause ---

    public function testMessageWithCauseNoPrevious(): void
    {
        $subject = $this->createSubject();
        $e = new \RuntimeException('Top level error');

        $this->assertSame('Top level error', $subject->messageWithCause($e));
    }

    public function testMessageWithCauseOneLevelDeep(): void
    {
        $subject = $this->createSubject();
        $root = new \RuntimeException('Connection refused');
        $e = new \RuntimeException('API call failed', 0, $root);

        $this->assertSame(
            'API call failed (caused by: Connection refused)',
            $subject->messageWithCause($e)
        );
    }

    public function testMessageWithCauseThreeLevelsDeep(): void
    {
        $subject = $this->createSubject();
        $curl = new \RuntimeException('cURL error 28: Operation timed out');
        $guzzle = new \RuntimeException('Guzzle request failed', 0, $curl);
        $api = new \RuntimeException('API call failed', 0, $guzzle);

        // Should show the root cause (cURL), not the intermediate (Guzzle)
        $this->assertSame(
            'API call failed (caused by: cURL error 28: Operation timed out)',
            $subject->messageWithCause($api)
        );
    }

    // --- writeExceptionChain ---

    public function testWriteExceptionChainSilentAtNormalVerbosity(): void
    {
        $subject = $this->createSubject();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $io = $this->makeIo($output);

        $root = new \RuntimeException('Root cause');
        $e = new \RuntimeException('Top', 0, $root);

        $subject->writeExceptionChain($io, $output, $e);

        $this->assertSame('', $output->fetch());
    }

    public function testWriteExceptionChainShowsCauseAtVerbose(): void
    {
        $subject = $this->createSubject();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $io = $this->makeIo($output);

        $root = new \RuntimeException('Connection refused');
        $e = new \RuntimeException('API failed', 0, $root);

        $subject->writeExceptionChain($io, $output, $e);

        $text = $output->fetch();
        $this->assertStringContainsString('Caused by RuntimeException: Connection refused', $text);
        // Should NOT include trace at -v
        $this->assertStringNotContainsString('#0', $text);
    }

    public function testWriteExceptionChainShowsTraceAtVeryVerbose(): void
    {
        $subject = $this->createSubject();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $io = $this->makeIo($output);

        $e = new \RuntimeException('Error');

        $subject->writeExceptionChain($io, $output, $e);

        $text = $output->fetch();
        // Should include trace at -vv
        $this->assertStringContainsString('#0', $text);
    }

    public function testWriteExceptionChainWalksFullChain(): void
    {
        $subject = $this->createSubject();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $io = $this->makeIo($output);

        $curl = new \RuntimeException('cURL timeout');
        $guzzle = new \RuntimeException('Guzzle failed', 0, $curl);
        $api = new \RuntimeException('API error', 0, $guzzle);

        $subject->writeExceptionChain($io, $output, $api);

        $text = $output->fetch();
        $this->assertStringContainsString('Caused by RuntimeException: Guzzle failed', $text);
        $this->assertStringContainsString('Caused by RuntimeException: cURL timeout', $text);
    }
}
