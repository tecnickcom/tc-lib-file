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
 * Dir class test
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
     * The default starting directory is src/, so 'src' resolves to the src/
     * inside the library root one level up.
     *
     * A name that exists nowhere up to the filesystem root returns ''. Only a
     * plain directory name is accepted: an empty, absolute or separator-bearing
     * name, and one containing a '..' segment, is reported as not found.
     *
     * @return array<string, array{string, string}>
     */
    public static function findParentDirDataProvider(): array
    {
        $srcDir = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'src' . \DIRECTORY_SEPARATOR;

        return [
            'name found while walking up' => ['src', $srcDir],
            'name found nowhere' => ['missing_dir_' . \uniqid(), ''],
            'empty name is not a match' => ['', ''],
            'current-directory name is not a match' => ['.', ''],
            'parent-directory name is not a match' => ['..', ''],
            'traversal escaping the walk is rejected' => ['../src', ''],
            'backslash traversal is rejected' => ['..\\src', ''],
            'absolute name is rejected' => [\DIRECTORY_SEPARATOR . 'tmp', ''],
            'embedded separator is rejected' => ['tc-lib-file/src', ''],
            'windows drive designator is rejected' => ['C:src', ''],
            'nul byte is rejected' => ["src\0", ''],
        ];
    }

    /**
     * A writable regular file bearing the searched name is not a match:
     * is_writable() is true for files too, so only the is_dir() test keeps the
     * search from returning a path that no caller can write into.
     */
    public function testFindParentDirIgnoresRegularFileOfTheSameName(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $file = $base . \DIRECTORY_SEPARATOR . 'cache';
        $start = $base . \DIRECTORY_SEPARATOR . 'inner';

        $this->assertTrue(\mkdir($start, 0o777, true));
        $this->assertNotFalse(\file_put_contents($file, 'not a directory'));

        try {
            $this->assertTrue(\is_writable($file));
            $this->assertFalse(\is_dir($file));

            // The walk must step over $file and keep going, not return it.
            $this->assertNotSame($file . \DIRECTORY_SEPARATOR, $this->getTestObject()->findParentDir('cache', $start));
        } finally {
            \unlink($file);
            \rmdir($start);
            \rmdir($base);
        }
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

            $this->assertTrue(\chmod($target, 0o555));
            \clearstatcache(true, $target);

            // The observed effect is checked rather than the uid: root and
            // some ACL or filesystem configurations ignore the mode bits.
            if (\is_writable($target)) {
                $this->markTestSkipped('permission bits are not enforced for this user');
            }

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
     * above the allowed paths. Runs in a separate process because
     * open_basedir cannot be relaxed once set.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFindParentDirUnderOpenBasedir(): void
    {
        $libRoot = \dirname(__DIR__);

        // File access is restricted to the library tree; the temp directory
        // is required by the test runner. The ancestors of $libRoot fall
        // outside the restriction and are skipped rather than probed.
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

    /**
     * A candidate that is exactly an open_basedir base, rather than something
     * below one, is still probed. This is the positive control for the test
     * above, which only asserts a negative result.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFindParentDirMatchesACandidateEqualToAnOpenBasedirBase(): void
    {
        $libRoot = \dirname(__DIR__);
        $tmp = \realpath(\sys_get_temp_dir());
        $this->assertIsString($tmp);

        // The temp directory is listed as a base, and its own name is what the
        // walk looks for, so the winning candidate equals the base exactly.
        $name = \basename($tmp);
        $parent = \dirname($tmp);

        if ($parent === $tmp || $name === '') {
            $this->markTestSkipped('the system temp directory is a filesystem root');
        }

        // @mago-expect lint:no-ini-set -- open_basedir can only be set at runtime in a test.
        \ini_set('open_basedir', $libRoot . PATH_SEPARATOR . $tmp);

        $warnings = [];
        \set_error_handler(static function (int $_errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $dir = $this->getTestObject()->findParentDir($name, $parent);
        } finally {
            \restore_error_handler();
        }

        $this->assertSame([], $warnings, 'open_basedir restriction must not raise warnings');
        $this->assertSame($tmp . \DIRECTORY_SEPARATOR, $dir);
    }

    /**
     * A relative starting directory walks the same ancestors as the absolute
     * path naming it, because it is anchored to the current working directory
     * first.
     */
    public function testFindParentDirTreatsARelativeStartLikeItsAbsoluteForm(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $target = $base . \DIRECTORY_SEPARATOR . 'cache';
        $start = $base . \DIRECTORY_SEPARATOR . 'a' . \DIRECTORY_SEPARATOR . 'b';

        $this->assertTrue(\mkdir($start, 0o777, true));
        $this->assertTrue(\mkdir($target, 0o777, true));

        $cwd = \getcwd();
        $this->assertIsString($cwd);

        try {
            $this->assertTrue(\chdir($base));

            $testObj = $this->getTestObject();

            // './cache' is directly above the relative start, and is found.
            $relative = $testObj->findParentDir('cache', 'a' . \DIRECTORY_SEPARATOR . 'b');
            $absolute = $testObj->findParentDir('cache', $start);

            $this->assertSame($absolute, $relative);
            $this->assertStringEndsWith(\DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR, $relative);

            // A name that exists nowhere in the ancestry is still "not found",
            // rather than resolving against the filesystem root.
            $this->assertSame('', $testObj->findParentDir('tclf_no_such_dir', 'a' . \DIRECTORY_SEPARATOR . 'b'));

            // An empty starting directory reports "not found" rather than
            // searching the current directory tree.
            $this->assertSame('', $testObj->findParentDir('cache', ''));
        } finally {
            \chdir($cwd);
            \rmdir($target);
            \rmdir($start);
            \rmdir($base . \DIRECTORY_SEPARATOR . 'a');
            \rmdir($base);
        }
    }
}
