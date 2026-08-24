<?php

/**
 * DirTest.php
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 *
 * This file is part of tc-lib-file software library.
 */

namespace Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * File Color class test
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
class DirTest extends TestUtil
{
    protected function getTestObject(): \Com\Tecnick\File\Dir
    {
        return new \Com\Tecnick\File\Dir();
    }

    /**
     * @param string $name     Directory name to search for
     * @param string $expected Expected exact return value of findParentDir()
     */
    #[DataProvider('findParentDirDataProvider')]
    public function testFindParentDir(string $name, string $expected): void
    {
        $testObj = $this->getTestObject();
        $this->assertSame($expected, $testObj->findParentDir($name));
    }

    /**
     * The default starting directory is src/, so an empty name resolves to it
     * and 'src' resolves to the src/ inside the library root one level up.
     *
     * A name that exists nowhere up to the filesystem root returns '': the
     * search reports "not found" with an empty string, which is the only value
     * a caller can distinguish from a match at the root.
     *
     * @return array<string, array{string, string}>
     */
    public static function findParentDirDataProvider(): array
    {
        $srcDir = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'src' . \DIRECTORY_SEPARATOR;

        return [
            'empty name resolves to the starting directory' => ['', $srcDir],
            'name found while walking up' => ['src', $srcDir],
            'name found nowhere' => ['missing_dir_' . \uniqid(), ''],
        ];
    }

    /**
     * A read-only directory of the right name is not a match: the search looks
     * for a writable directory.
     */
    public function testFindParentDirRequiresWritableDirectory(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $target = $base . \DIRECTORY_SEPARATOR . 'cache';
        $start = $target . \DIRECTORY_SEPARATOR . 'inner';

        $this->assertTrue(\mkdir($start, 0o777, true));

        try {
            $this->assertSame($target . \DIRECTORY_SEPARATOR, $this->getTestObject()->findParentDir('cache', $start));

            if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
                $this->markTestSkipped('running as root: permission bits are not enforced');
            }

            $this->assertTrue(\chmod($target, 0o555));
            $this->assertSame('', $this->getTestObject()->findParentDir('cache', $start));
        } finally {
            \chmod($target, 0o777);
            \rmdir($start);
            \rmdir($target);
            \rmdir($base);
        }
    }

    /**
     * The upward directory search must not raise open_basedir warnings when it walks
     * above the allowed paths (see issue #238). Runs in a separate process because
     * open_basedir cannot be relaxed once set.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFindParentDirUnderOpenBasedir(): void
    {
        $libRoot = \dirname(__DIR__);

        // Restrict file access to the library tree (the temp dir is required by the
        // test runner). The ancestors of $libRoot fall outside this restriction, so
        // probing them would raise open_basedir warnings without the guard.
        // @mago-expect lint:no-ini-set -- open_basedir can only be set at runtime in a test.
        \ini_set('open_basedir', $libRoot . PATH_SEPARATOR . \sys_get_temp_dir());

        $warnings = [];
        \set_error_handler(static function (int $_errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $dir = $this->getTestObject()->findParentDir('missing', $libRoot . '/src');
        } finally {
            \restore_error_handler();
        }

        $this->assertSame([], $warnings, 'open_basedir restriction must not raise warnings');
        // The name exists nowhere under the restriction, and every ancestor
        // outside it is skipped rather than probed, so the search reports
        // "not found".
        $this->assertSame('', $dir);
    }
}
