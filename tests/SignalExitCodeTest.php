<?php

/*
 * This file is part of composer/xdebug-handler.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Composer\XdebugHandler\Tests;

use PHPUnit\Framework\TestCase;

class SignalExitCodeTest extends TestCase
{
    protected function setUp(): void
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            self::markTestSkipped('Signal handling tests are not applicable on Windows');
        }

        if (!function_exists('posix_kill') || !function_exists('posix_getpid')) {
            self::markTestSkipped('posix extension is required');
        }
    }

    public function testProcCloseReturnsRawSignalNumberForSigterm(): void
    {
        $script = 'posix_kill(posix_getpid(), 15);'; // SIGTERM
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [],
            $pipes
        );

        self::assertIsResource($process);

        // proc_close returns the raw signal number (15), not 128+15
        $exitCode = proc_close($process);
        self::assertSame(15, $exitCode);
    }

    public function testProcCloseReturnsRawSignalNumberForSegfault(): void
    {
        $script = 'posix_kill(posix_getpid(), 11);'; // SIGSEGV
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [],
            $pipes
        );

        self::assertIsResource($process);

        $exitCode = proc_close($process);
        self::assertSame(11, $exitCode);
    }

    public function testProcGetStatusDetectsSignalDeath(): void
    {
        $script = 'posix_kill(posix_getpid(), 15);'; // SIGTERM
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [],
            $pipes
        );

        self::assertIsResource($process);

        $status = proc_get_status($process);
        while ($status['running']) {
            usleep(10_000);
            $status = proc_get_status($process);
        }

        self::assertTrue($status['signaled']);
        self::assertSame(15, $status['termsig']);
        self::assertSame(143, 128 + $status['termsig']);

        proc_close($process);
    }

    public function testProcGetStatusDetectsSegfault(): void
    {
        $script = 'posix_kill(posix_getpid(), 11);'; // SIGSEGV
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [],
            $pipes
        );

        self::assertIsResource($process);

        $status = proc_get_status($process);
        while ($status['running']) {
            usleep(10_000);
            $status = proc_get_status($process);
        }

        self::assertTrue($status['signaled']);
        self::assertSame(11, $status['termsig']);
        self::assertSame(139, 128 + $status['termsig']);

        proc_close($process);
    }

    public function testNormalExitCodeIsPreserved(): void
    {
        $script = 'exit(42);';
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [],
            $pipes
        );

        self::assertIsResource($process);

        $status = proc_get_status($process);
        while ($status['running']) {
            usleep(10_000);
            $status = proc_get_status($process);
        }

        self::assertFalse($status['signaled']);
        self::assertSame(42, $status['exitcode']);

        proc_close($process);
    }
}
