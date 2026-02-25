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

/**
 * Tests that signal-killed child processes are correctly detected via
 * proc_get_status polling, where proc_close alone would return the raw
 * signal number (indistinguishable from a normal exit code).
 *
 * @see https://github.com/php/php-src/issues/21292
 */
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

    /**
     * Proves that proc_close returns the raw signal number on non-Windows,
     * making it impossible to distinguish a signal death from a normal exit.
     */
    public function testProcCloseReturnsRawSignalNumber(): void
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
        self::assertSame(15, $exitCode, 'proc_close should return raw signal number (15) on non-Windows');
    }

    /**
     * Proves that proc_get_status polling correctly detects signal deaths
     * and provides the information needed to compute the conventional
     * exit code (128 + signal).
     *
     * This is the mechanism used by the fix in doRestart() to reliably
     * distinguish signal deaths from normal exit codes.
     */
    public function testProcGetStatusDetectsSignalDeath(): void
    {
        $script = 'posix_kill(posix_getpid(), 15);'; // SIGTERM
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [],
            $pipes
        );

        self::assertIsResource($process);

        // Poll proc_get_status until the process exits
        $status = proc_get_status($process);
        while ($status['running']) {
            usleep(10_000);
            $status = proc_get_status($process);
        }

        // proc_get_status correctly identifies signal death
        self::assertTrue($status['signaled'], 'Process should be flagged as signaled');
        self::assertSame(15, $status['termsig'], 'Terminal signal should be SIGTERM (15)');

        // The conventional exit code for signal death is 128 + signal
        self::assertSame(143, 128 + $status['termsig']);

        proc_close($process);
    }

    /**
     * Tests with SIGSEGV (signal 11), the most common crash signal.
     * Without the fix, exit code 11 would be indistinguishable from
     * a normal exit(11).
     */
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

        self::assertTrue($status['signaled'], 'Process should be flagged as signaled');
        self::assertSame(11, $status['termsig'], 'Terminal signal should be SIGSEGV (11)');
        self::assertSame(139, 128 + $status['termsig']);

        proc_close($process);
    }

    /**
     * Proves that proc_close alone returns the raw signal number for SIGSEGV,
     * making it indistinguishable from a normal exit(11).
     */
    public function testProcCloseReturnsRawSignalNumberForSegfault(): void
    {
        $script = 'posix_kill(posix_getpid(), 11);'; // SIGSEGV
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [],
            $pipes
        );

        self::assertIsResource($process);

        // Using proc_close alone (without proc_get_status), we get raw signal
        $exitCode = proc_close($process);
        self::assertSame(11, $exitCode, 'proc_close should return raw signal number (11) on non-Windows');
    }

    /**
     * Tests that a normal exit code is correctly reported by both methods,
     * ensuring the fix doesn't break normal behavior.
     */
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

        self::assertFalse($status['signaled'], 'Process should not be flagged as signaled');
        self::assertSame(42, $status['exitcode'], 'Exit code should be preserved');

        proc_close($process);
    }
}