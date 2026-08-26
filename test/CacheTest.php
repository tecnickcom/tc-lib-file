<?php

/**
 * CacheTest.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
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
 * Cache class test
 *
 * @since     2011-05-23
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
class CacheTest extends TestUtil
{
    /**
     * Build a cache instance with a prefix unique to this test run.
     *
     * delete() and deleteOlderThan() act on every file carrying the instance
     * prefix in the shared system temp directory, so a fixed prefix would make
     * two concurrent runs delete each other's files.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    protected function getTestObject(): \Com\Tecnick\File\Cache
    {
        return new \Com\Tecnick\File\Cache('tclf' . \uniqid('', false));
    }

    /**
     * Delete a directory and everything directly inside it.
     *
     * @param string $dir Directory to remove.
     */
    private static function removeDirectory(string $dir): void
    {
        $entries = \scandir($dir);
        if ($entries !== false) {
            foreach (\array_diff($entries, ['.', '..']) as $entry) {
                \unlink($dir . \DIRECTORY_SEPARATOR . $entry);
            }
        }

        \rmdir($dir);
    }

    /**
     * Create an empty cache directory unique to this test.
     *
     * @return string Path of the created directory.
     */
    private static function makeCacheDir(): string
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf' . \uniqid('', true);
        \mkdir($dir, 0o700, true);

        return $dir;
    }

    /**
     * Tells whether a file can actually be created in the given directory.
     *
     * @param string $dir Directory to probe.
     */
    private static function canCreateFileIn(string $dir): bool
    {
        // tempnam() raises a notice when it falls back to the system temp
        // directory; the return value is what the probe acts on.
        \set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE);

        try {
            $probe = \tempnam($dir, 'probe');
        } finally {
            \restore_error_handler();
        }

        if ($probe === false) {
            return false;
        }

        \unlink($probe);

        // A file created elsewhere means the directory refused the write.
        return \realpath(\dirname($probe)) === \realpath($dir);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetFilePrefixSanitizesUnsafeCharacters(): void
    {
        // '+' and '/' map to '-'; everything outside [A-Za-z0-9-] is dropped,
        // '_' included, because it separates the fields of a generated name.
        // No file is created, so a fixed prefix is safe here.
        $cache = new \Com\Tecnick\File\Cache('1_2-a+B/c');
        $this->assertSame('_12-a-B-c_', $cache->getFilePrefix());
    }

    /**
     * A prefix left empty by sanitization must not collapse to '__': every such
     * instance would share it, and the prefix scan would then match unrelated
     * files in the shared system temp directory used by default.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[DataProvider('emptySanitizedPrefixProvider')]
    public function testPrefixThatSanitizesToEmptyFallsBackToARandomOne(string $input): void
    {
        $prefix = (new \Com\Tecnick\File\Cache($input))->getFilePrefix();

        $this->assertNotSame('__', $prefix);
        $this->assertSame(1, \preg_match('/^_[a-f0-9]{32}_$/', $prefix), $prefix);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptySanitizedPrefixProvider(): array
    {
        return [
            'empty' => [''],
            'only stripped characters' => ['***'],
            'only whitespace' => ['   '],
            'a single underscore' => ['_'],
            'only underscores' => ['___'],
        ];
    }

    /**
     * The type and key fields are separated by '_' in a generated name, so '_'
     * must not survive sanitization inside a field: getNewFileName('a', 'b-c')
     * and getNewFileName('a-b', 'c') must not produce the same name shape, and
     * delete() must not reach across a field boundary.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteDoesNotReachAcrossFieldBoundaries(): void
    {
        $dir = self::makeCacheDir();

        try {
            $cache = (new \Com\Tecnick\File\Cache('scope'))->setCachePath($dir);

            $target = $cache->getNewFileName('img', 'k1');
            $siblingKey = $cache->getNewFileName('img', 'k1-thumb');
            $siblingType = $cache->getNewFileName('img-thumb', 'k1');

            $this->assertNotSame($siblingKey, $siblingType);

            $cache->delete('img', 'k1');

            $this->assertFileDoesNotExist($target);
            $this->assertFileExists($siblingKey);
            $this->assertFileExists($siblingType);

            $cache->delete('img');

            $this->assertFileDoesNotExist($siblingKey);
            $this->assertFileExists($siblingType);
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * A cache must never delete a file belonging to an instance whose prefix
     * merely starts with its own.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteDoesNotReachIntoAnotherInstancePrefix(): void
    {
        $dir = self::makeCacheDir();

        try {
            $app = (new \Com\Tecnick\File\Cache('app'))->setCachePath($dir);
            $appV2 = (new \Com\Tecnick\File\Cache('app-v2'))->setCachePath($dir);

            $ownFile = $app->getNewFileName('t', '1');
            $otherFile = $appV2->getNewFileName('t', '1');

            $app->delete();

            $this->assertFileDoesNotExist($ownFile);
            $this->assertFileExists($otherFile);

            $app->deleteOlderThan(0);

            $this->assertFileExists($otherFile);
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * A negative age puts the cutoff in the future, which would delete every
     * file for the prefix instead of only the stale ones.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteOlderThanRejectsANegativeAge(): void
    {
        $dir = self::makeCacheDir();

        try {
            $cache = (new \Com\Tecnick\File\Cache('age'))->setCachePath($dir);
            $file = $cache->getNewFileName('t', '1');

            try {
                $cache->deleteOlderThan(-1);
                $this->fail('deleteOlderThan() accepted a negative age');
            } catch (\Com\Tecnick\File\Exception $exception) {
                $this->assertSame('the maximum age must not be negative, got: -1', $exception->getMessage());
            }

            $this->assertFileExists($file);
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * setCachePath() returns the instance so it can be chained, like every
     * setter on File.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSetCachePathIsFluent(): void
    {
        $dir = self::makeCacheDir();

        try {
            $cache = new \Com\Tecnick\File\Cache('fluent');

            $this->assertSame($cache, $cache->setCachePath($dir));

            $real = \realpath($dir);
            $this->assertNotFalse($real);
            $this->assertSame($real . \DIRECTORY_SEPARATOR, $cache->getCachePath());
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAutoPrefix(): void
    {
        $cache = new \Com\Tecnick\File\Cache();
        $this->assertNotEmpty($cache->getFilePrefix());
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetCachePath(): void
    {
        $cache = $this->getTestObject();
        $cachePath = $cache->getCachePath();
        // The normalized cache path always ends with the platform separator.
        $this->assertSame(\DIRECTORY_SEPARATOR, \substr($cachePath, -1));

        $cache->setCachePath();
        $this->assertSame($cachePath, $cache->getCachePath());

        // Use the real temp dir and compare realpath-to-realpath so the
        // assertion holds on every platform (e.g. macOS /var -> /private/var
        // symlink, Windows backslash separators).
        $path = \sys_get_temp_dir();
        $real = \realpath($path);
        if ($real === false) {
            self::fail('the system temp dir must resolve');
        }

        $cache->setCachePath($path);
        $this->assertSame($real . \DIRECTORY_SEPARATOR, $cache->getCachePath());
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetFilePrefix(): void
    {
        $cache = $this->getTestObject();
        $filePrefix = $cache->getFilePrefix();
        $this->assertStringStartsWith('_tclf', $filePrefix);
        $this->assertStringEndsWith('_', $filePrefix);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetNewFileName(): void
    {
        $cache = $this->getTestObject();
        $val = $cache->getNewFileName('tst', '0123');

        try {
            $this->assertStringStartsWith($cache->getFilePrefix() . 'tst_0123_', \basename($val));
        } finally {
            if (\is_file($val)) {
                \unlink($val);
            }
        }
    }

    /**
     * The full prefix, type and key must survive in the generated filename so
     * the prefix-based glob in delete()/deleteOlderThan() matches on every
     * platform. tempnam() alone truncates the prefix to 3 chars on Windows.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetNewFileNamePreservesFullPrefix(): void
    {
        $cache = $this->getTestObject();
        $fileA = $cache->getNewFileName('typ', 'k1');
        $fileB = $cache->getNewFileName('typ', 'k2');

        try {
            $this->assertNotSame($fileA, $fileB, 'each call must return a distinct file');
            $this->assertTrue(\file_exists($fileA));
            $this->assertTrue(\file_exists($fileB));
            $this->assertStringStartsWith($cache->getFilePrefix() . 'typ_k1_', \basename($fileA));
            $this->assertStringStartsWith($cache->getFilePrefix() . 'typ_k2_', \basename($fileB));
        } finally {
            if (\is_file($fileA)) {
                \unlink($fileA);
            }

            if (\is_file($fileB)) {
                \unlink($fileB);
            }
        }
    }

    /**
     * Characters that are invalid in Windows filenames (and glob metacharacters)
     * are stripped from the generated name, keeping it valid and consistent with
     * the patterns delete() builds.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetNewFileNameSanitizesUnsafeTypeAndKey(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('a/b*', 'c:d?');

        try {
            $this->assertStringStartsWith($cache->getFilePrefix() . 'ab_cd_', \basename($file));
            $this->assertTrue(\file_exists($file));
        } finally {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
    }

    /**
     * An unresolvable cache directory is reported rather than accepted as '',
     * which would make tempnam() write to the system temp directory while
     * delete() scans the working directory.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testNormalizePathInvalidThrows(): void
    {
        $cache = $this->getTestObject();

        // invoke protected normalizePath via reflection so we can test
        // the branch where realpath() returns false
        $ref = new \ReflectionMethod($cache, 'normalizePath');

        $invalid = \sys_get_temp_dir() . '/nonexistent_' . \uniqid('', true);
        $this->assertFalse(\file_exists($invalid), 'Sanity check: path should not exist');

        $this->expectException(\Com\Tecnick\File\Exception::class);
        $ref->invoke($cache, $invalid);
    }

    /**
     * A path that names a stream wrapper, is not a directory, or is not
     * writable falls back to K_PATH_CACHE instead of being used.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSetCachePathRejectsUnusablePaths(): void
    {
        $cache = $this->getTestObject();
        $fallback = $cache->getCachePath();

        // A stream wrapper is never a usable cache directory.
        $cache->setCachePath('php://memory');
        $this->assertSame($fallback, $cache->getCachePath());

        // A path that does not exist.
        $cache->setCachePath(\sys_get_temp_dir() . '/missing_' . \uniqid('', true));
        $this->assertSame($fallback, $cache->getCachePath());

        // An existing file is not a directory.
        $file = \tempnam(\sys_get_temp_dir(), 'tclf');
        $this->assertNotFalse($file);

        try {
            $cache->setCachePath($file);
            $this->assertSame($fallback, $cache->getCachePath());
        } finally {
            \unlink($file);
        }
    }

    /**
     * The filesystem root already ends with a separator, so normalizePath()
     * must not append a second one.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testNormalizePathDoesNotDoubleTheTrailingSeparator(): void
    {
        $cache = $this->getTestObject();
        $ref = new \ReflectionMethod($cache, 'normalizePath');

        $root = \realpath(\DIRECTORY_SEPARATOR);
        if ($root === false || !\str_ends_with($root, \DIRECTORY_SEPARATOR)) {
            $this->markTestSkipped('the filesystem root does not resolve to a trailing separator here');
        }

        $this->assertSame($root, $ref->invoke($cache, \DIRECTORY_SEPARATOR));
    }

    /**
     * A cache directory whose name contains glob metacharacters must still be
     * searchable: a glob() pattern built from such a path silently matches
     * nothing, so every cache file would leak.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteWorksWhenCachePathContainsGlobMetacharacters(): void
    {
        // '[' and ']' are glob metacharacters and are legal in a filename on
        // every platform; '*' and '?' are also glob metacharacters but are
        // illegal in a Windows filename, so they are only added elsewhere.
        $meta = \DIRECTORY_SEPARATOR === '\\' ? '' : '*?';
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf[' . \uniqid('', false) . ']' . $meta;
        $this->assertTrue(\mkdir($dir, 0o700));

        try {
            $cache = $this->getTestObject();
            $cache->setCachePath($dir);
            $real = \realpath($dir);
            $this->assertNotFalse($real);
            $this->assertSame($real . \DIRECTORY_SEPARATOR, $cache->getCachePath());

            $file = $cache->getNewFileName('typ', 'key');
            $this->assertTrue(\is_file($file));

            $cache->delete();
            $this->assertFalse(\file_exists($file), 'glob metacharacters in the cache path must not defeat delete()');
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * deleteOlderThan() shares the same directory scan, so it must survive a
     * cache path containing glob metacharacters too.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteOlderThanWorksWhenCachePathContainsGlobMetacharacters(): void
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf[' . \uniqid('', false) . ']';
        $this->assertTrue(\mkdir($dir, 0o700));

        try {
            $cache = $this->getTestObject();
            $cache->setCachePath($dir);

            $file = $cache->getNewFileName('aged', '1');
            $this->assertTrue(\touch($file, \time() - 7200));

            $cache->deleteOlderThan(3600);
            $this->assertFalse(\file_exists($file));
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDelete(): void
    {
        $cache = $this->getTestObject();
        $idk = 0;
        /** @var array<int, string> $file */
        $file = [];
        for ($idx = 1; $idx <= 2; ++$idx) {
            for ($idy = 1; $idy <= 2; ++$idy) {
                $file[$idk] = $cache->getNewFileName((string) $idx, (string) $idy);
                \file_put_contents($file[$idk], '');
                $this->assertTrue(\file_exists($file[$idk]));
                ++$idk;
            }
        }

        $f0 = $file[0] ?? '';
        $f1 = $file[1] ?? '';
        $f2 = $file[2] ?? '';
        $f3 = $file[3] ?? '';

        try {
            // delete a specific type/key pair
            $cache->delete('2', '1');
            $this->assertFalse(\file_exists($f2));

            // delete all entries for type "1"
            $cache->delete('1');
            $this->assertFalse(\file_exists($f0));
            $this->assertFalse(\file_exists($f1));
            $this->assertTrue(\file_exists($f3));

            // delete everything
            $cache->delete();
            $this->assertFalse(\file_exists($f3));
        } finally {
            $cache->delete();
        }
    }

    /**
     * A key cannot be matched without a type, because the file name is built
     * as prefix + type + key, so the call is rejected.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testKeyWithoutTypeIsRejected(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('foo', 'bar');
        \file_put_contents($file, '');
        $this->assertTrue(\file_exists($file));

        try {
            $thrown = null;

            try {
                $cache->delete(null, 'bar');
            } catch (\Com\Tecnick\File\Exception $exception) {
                $thrown = $exception;
            }

            $this->assertInstanceOf(\Com\Tecnick\File\Exception::class, $thrown);
            // The rejected call must be inert: the entry is still there.
            $this->assertTrue(\file_exists($file));
        } finally {
            $cache->delete();
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteNonExistingPatterns(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('foo', 'bar');
        \file_put_contents($file, '');
        $this->assertTrue(\file_exists($file));

        try {
            // deleting a type that does not exist should leave the file in place
            $cache->delete('no-such-type');
            $this->assertTrue(\file_exists($file));

            // deleting a non-existent key under existing type
            $cache->delete('foo', 'no-such-key');
            $this->assertTrue(\file_exists($file));
        } finally {
            $cache->delete();
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testEachInstanceHasOwnPrefix(): void
    {
        // Each instance should have its own prefix
        $cache1 = new \Com\Tecnick\File\Cache('pfx1');
        $cache2 = new \Com\Tecnick\File\Cache('pfx2');

        $prefix1 = $cache1->getFilePrefix();
        $prefix2 = $cache2->getFilePrefix();

        // Prefixes should be different since each instance is independent
        $this->assertNotSame($prefix1, $prefix2);
        $this->assertStringContainsString('pfx1', $prefix1);
        $this->assertStringContainsString('pfx2', $prefix2);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testEachInstanceHasOwnCachePath(): void
    {
        $cache1 = new \Com\Tecnick\File\Cache();
        $path1 = $cache1->getCachePath();

        $tempdir = \sys_get_temp_dir() . '/cache_test_' . \uniqid();
        $this->assertTrue(\mkdir($tempdir));

        try {
            $cache2 = new \Com\Tecnick\File\Cache();
            $cache2->setCachePath($tempdir);
            $path2 = $cache2->getCachePath();

            // Paths should be different for each instance
            $this->assertNotSame($path1, $path2);
            $this->assertStringContainsString('cache_test_', $path2);
        } finally {
            \rmdir($tempdir);
        }
    }

    // -------------------------------------------------------------------------
    // Sanitisation of the delete() name patterns
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteGlobCharsInTypeSanitised(): void
    {
        $cache = $this->getTestObject();

        // Create a real file we do NOT want deleted.
        $real = $cache->getNewFileName('safe', '1');
        \file_put_contents($real, '');
        $this->assertTrue(\file_exists($real));

        try {
            // Glob metacharacters in $type must not expand.
            $cache->delete('*', null);

            // The real file survives because '*' is stripped and the
            // resulting prefix matches nothing.
            $this->assertTrue(\file_exists($real), 'Metacharacters in $type must not delete unrelated files');
        } finally {
            $cache->delete();
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteGlobCharsInKeySanitised(): void
    {
        $cache = $this->getTestObject();

        $real = $cache->getNewFileName('mytype', 'goodkey');
        \file_put_contents($real, '');
        $this->assertTrue(\file_exists($real));

        try {
            // A glob metacharacter in $key must be stripped.
            $cache->delete('mytype', '?');

            $this->assertTrue(\file_exists($real), 'Metacharacters in $key must not delete unrelated files');
        } finally {
            $cache->delete();
        }
    }

    // -------------------------------------------------------------------------
    // Per-instance cache path and prefix
    // -------------------------------------------------------------------------

    // Testing covered by testEachInstanceHasOwnPrefix() and testEachInstanceHasOwnCachePath()

    // -------------------------------------------------------------------------
    // Cache file creation
    // -------------------------------------------------------------------------

    // The tempnam() failure path of getNewFileName() is not covered here:
    // tempnam() falls back to the system temporary directory instead of
    // returning false.

    // -------------------------------------------------------------------------
    // Age-based deletion
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteOlderThanNoFiles(): void
    {
        // Call deleteOlderThan() when no files exist for this cache prefix, so
        // the empty-result early return is exercised.
        $cache = new \Com\Tecnick\File\Cache('emptyprefix' . \uniqid('', false));

        /** @var array<int, string> $before */
        $before = (new \ReflectionMethod($cache, 'findFiles'))->invoke($cache, $cache->getFilePrefix());
        $this->assertSame([], $before);

        $cache->deleteOlderThan(3600);

        /** @var array<int, string> $after */
        $after = (new \ReflectionMethod($cache, 'findFiles'))->invoke($cache, $cache->getFilePrefix());
        $this->assertSame([], $after);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteNoFiles(): void
    {
        $cache = new \Com\Tecnick\File\Cache('emptyprefix' . \uniqid('', false));

        /** @var array<int, string> $before */
        $before = (new \ReflectionMethod($cache, 'findFiles'))->invoke($cache, $cache->getFilePrefix());
        $this->assertSame([], $before);

        $cache->delete();

        /** @var array<int, string> $after */
        $after = (new \ReflectionMethod($cache, 'findFiles'))->invoke($cache, $cache->getFilePrefix());
        $this->assertSame([], $after);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteOlderThan(): void
    {
        $cache = $this->getTestObject();

        $old = $cache->getNewFileName('aged', '1');
        $fresh = $cache->getNewFileName('aged', '2');
        \file_put_contents($old, '');
        \file_put_contents($fresh, '');

        try {
            // Back-date the "old" file to 2 hours ago.
            \touch($old, \time() - 7200);

            // Delete files older than 1 hour.
            $cache->deleteOlderThan(3600);

            $this->assertFalse(\file_exists($old), 'Expired file must be deleted');
            $this->assertTrue(\file_exists($fresh), 'Fresh file must be kept');
        } finally {
            $cache->delete();
        }
    }

    /**
     * A K_PATH_CACHE without a trailing separator is normalized, so generated
     * files land inside the cache directory rather than next to it.
     *
     * Runs in a separate process, because K_PATH_CACHE cannot be redefined
     * once the cache class has set it.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetNewFileNameRespectsCachePathWithoutTrailingSeparator(): void
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tcfilecache_' . \uniqid('', true);
        $this->assertTrue(\mkdir($dir, 0o700));

        // Define K_PATH_CACHE without a trailing separator, before the cache class
        // has a chance to define it from sys_get_temp_dir().
        \define('K_PATH_CACHE', $dir);

        try {
            $cache = new \Com\Tecnick\File\Cache('trailsep');

            // The reported cache path must end with the platform separator.
            $cachePath = $cache->getCachePath();
            $this->assertSame(\DIRECTORY_SEPARATOR, \substr($cachePath, -1));

            $file = $cache->getNewFileName('tmp', '0');
            try {
                // The generated file must live inside the cache directory.
                $this->assertTrue(\str_starts_with($file, $cachePath));
                $this->assertSame(\realpath($dir), \realpath(\dirname($file)));
                $this->assertTrue(\is_file($file));
            } finally {
                if (\is_file($file)) {
                    \unlink($file);
                }
            }
        } finally {
            \rmdir($dir);
        }
    }

    /**
     * getNewFileName() reports an uncreatable cache file instead of returning a
     * path the caller cannot write to.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetNewFileNameThrowsWhenTheDirectoryIsUnusable(): void
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_ro_' . \uniqid('', true);
        $this->assertTrue(\mkdir($dir, 0o777, true));

        $cache = new class('rofail') extends \Com\Tecnick\File\Cache {
            public function pointAt(string $path): void
            {
                $this->path = \rtrim($path, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
            }
        };

        try {
            $this->assertTrue(\chmod($dir, 0o500));
            \clearstatcache(true, $dir);

            // Root, Windows and some ACL setups ignore the mode bits, in which
            // case tempnam() succeeds and there is nothing to assert. The
            // directory is probed with the same call getNewFileName() makes,
            // because is_writable() reports the Windows read-only attribute
            // that file creation there does not honour.
            if (self::canCreateFileIn($dir)) {
                $this->markTestSkipped('permission bits are not enforced for this user');
            }

            $cache->pointAt($dir);

            $before = \scandir(\sys_get_temp_dir());
            $this->assertIsArray($before);

            // The message tells the two failure paths apart: the tempnam()
            // fallback guard, not the later "cannot create a cache file" arm.
            try {
                $cache->getNewFileName('t', 'k');
                $this->fail('getNewFileName() accepted an unwritable cache directory');
            } catch (\Com\Tecnick\File\Exception $exception) {
                // The message tells the two failure paths apart: the
                // tempnam() fallback guard, not the later "cannot create a
                // cache file" arm.
                $this->assertSame(
                    'unable to create a temporary file in: ' . $dir . \DIRECTORY_SEPARATOR,
                    $exception->getMessage(),
                );
            } finally {
                // Nothing may be stranded in the system temp directory either.
                $after = \scandir(\sys_get_temp_dir());
                $this->assertIsArray($after);
                $this->assertSame(
                    [],
                    \array_values(\array_filter(
                        \array_diff($after, $before),
                        static fn(string $entry): bool => \str_starts_with($entry, $cache->getFilePrefix()),
                    )),
                );
            }
        } finally {
            \chmod($dir, 0o777);
            \rmdir($dir);
        }
    }

    /**
     * A directory scan that cannot run yields no files rather than an error, so
     * delete() on a vanished cache directory stays inert.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteToleratesAMissingCacheDirectory(): void
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_gone_' . \uniqid('', true);
        $this->assertTrue(\mkdir($dir, 0o777, true));

        $cache = new class('gone') extends \Com\Tecnick\File\Cache {
            public function pointAt(string $path): void
            {
                $this->path = \rtrim($path, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
            }
        };

        $cache->pointAt($dir);
        \rmdir($dir);

        // scandir() fails on the removed directory; the warning must be
        // swallowed and both entry points must simply do nothing.
        $cache->delete();
        $cache->delete('type');
        $cache->deleteOlderThan(0);

        $this->assertFalse(\is_dir($dir));
    }

    /**
     * The prefix scan only ever returns files: a directory bearing the prefix
     * would reach unlink() and fail there with the warning suppressed.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteSkipsDirectoriesBearingThePrefix(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('foo', 'bar');
        \file_put_contents($file, '');

        $decoy = $cache->getCachePath() . $cache->getFilePrefix() . 'foo_bar_directory';
        $this->assertTrue(\mkdir($decoy, 0o777));

        try {
            // The scan itself is asserted on: unlink() fails on a directory
            // with the warning suppressed, so the decoy survives delete()
            // whether or not the scan filters it out.
            /** @var array<int, string> $found */
            $found = (new \ReflectionMethod($cache, 'findFiles'))->invoke($cache, $cache->getFilePrefix());
            $this->assertSame([$file], \array_values($found));

            $cache->delete();

            $this->assertFalse(\file_exists($file));
            // The directory is left untouched rather than being passed to unlink().
            $this->assertTrue(\is_dir($decoy));
        } finally {
            \rmdir($decoy);
            $cache->delete();
        }
    }

    /**
     * The generated name really is sanitized, not merely harmless because the
     * scan does no globbing: the metacharacters must be gone from the filename.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGeneratedNameStripsUnsafeCharactersFromTypeAndKey(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('a*b', 'c?d');

        try {
            $expected = $cache->getFilePrefix() . 'ab_cd_';
            $this->assertStringStartsWith($expected, \basename($file));
        } finally {
            $cache->delete();
        }
    }

    /**
     * Two files created with the same type and key must not collide: the random
     * suffix is what keeps rename() from overwriting an existing entry.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetNewFileNameDoesNotClobberAnExistingEntry(): void
    {
        $cache = $this->getTestObject();

        try {
            $first = $cache->getNewFileName('img', 'k');
            \file_put_contents($first, 'first');

            $second = $cache->getNewFileName('img', 'k');
            \file_put_contents($second, 'second');

            $this->assertNotSame($first, $second);
            $this->assertTrue(\is_file($first));
            $this->assertSame('first', \file_get_contents($first));
            $this->assertSame('second', \file_get_contents($second));
        } finally {
            $cache->delete();
        }
    }

    /**
     * The generated prefix must not be derived from a predictable source, so two
     * instances created back to back never share one.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGeneratedPrefixesAreDistinct(): void
    {
        $prefixes = [];
        for ($i = 0; $i < 8; $i++) {
            $prefixes[] = (new \Com\Tecnick\File\Cache())->getFilePrefix();
        }

        $this->assertCount(8, \array_unique($prefixes));

        foreach ($prefixes as $prefix) {
            // Still a safe filename token after sanitization.
            $this->assertMatchesRegularExpression('/^_[a-zA-Z0-9\-]+_$/', $prefix);
        }
    }

    /**
     * When every candidate name is already taken, getNewFileName() gives up
     * rather than overwrite an existing entry, and it removes the temporary file
     * it created instead of returning a path delete() could never reclaim.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetNewFileNameGivesUpWhenEveryCandidateNameIsTaken(): void
    {
        // A unique prefix: with randomToken() pinned, a fixed one would
        // collide between concurrent runs.
        $cache = new class('collide' . \uniqid('', false)) extends \Com\Tecnick\File\Cache {
            /**
             * Make every attempt produce the same name so the collision branch
             * is reached deterministically instead of on a 1-in-2^128 chance.
             */
            protected function randomToken(int $bytes): string
            {
                return 'fixedtoken';
            }

            public function cacheDir(): string
            {
                return $this->path;
            }
        };

        // A regular file, not a directory: rename() fails on a directory
        // whatever the code does, while it would overwrite this file.
        $blocker = $cache->cacheDir() . $cache->getFilePrefix() . 'typ_key_fixedtoken';
        $this->assertNotFalse(\file_put_contents($blocker, 'PRECIOUS'));

        $before = \scandir($cache->cacheDir());
        $this->assertIsArray($before);

        try {
            $thrown = null;

            try {
                $cache->getNewFileName('typ', 'key');
            } catch (\Com\Tecnick\File\Exception $exception) {
                $thrown = $exception;
            }

            $this->assertInstanceOf(\Com\Tecnick\File\Exception::class, $thrown);

            // The blocking entry is untouched, and no tempnam() leftover carrying
            // this instance's prefix survives.
            $this->assertSame('PRECIOUS', \file_get_contents($blocker));

            $after = \scandir($cache->cacheDir());
            $this->assertIsArray($after);
            $leftovers = \array_filter(\array_diff($after, $before), static fn(string $entry): bool => \str_starts_with(
                $entry,
                $cache->getFilePrefix(),
            ));
            $this->assertSame([], \array_values($leftovers));
        } finally {
            \unlink($blocker);
        }
    }

    /**
     * K_PATH_CACHE defaults to upload_tmp_dir when one is set, and to the
     * system temp directory otherwise.
     *
     * Runs in a subprocess, because upload_tmp_dir is PHP_INI_SYSTEM and
     * K_PATH_CACHE is defined once per process.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSystemCachePathPrefersUploadTmpDir(): void
    {
        if (!\function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this environment');
        }

        // The directory is created canonical, so that the value read back from
        // the subprocess, which normalizes it with realpath(), can be compared
        // with the one passed in.
        $uploadDir = self::makeTempDir('tclf_upload_');

        $autoload = \dirname(__DIR__) . '/vendor/autoload.php';
        $script = 'require ' . \var_export($autoload, true) . '; echo (new \Com\Tecnick\File\Cache())->getCachePath();';

        try {
            // The ini value is quoted: the ini parser reads '~', '&', '|' and
            // '(' in a bare value as operators, and an 8.3 short name such as
            // 'C:\Users\RUNNER~1' would be cut at the tilde.
            $withUploadDir = self::runInSubprocess(['-d', 'upload_tmp_dir="' . $uploadDir . '"', '-r', $script]);
            if ($withUploadDir === null) {
                $this->markTestSkipped('unable to start a PHP subprocess in this environment');
            }

            $this->assertSame(
                $uploadDir . \DIRECTORY_SEPARATOR,
                $withUploadDir,
                'upload_tmp_dir must be preferred when it is set',
            );

            // With no upload_tmp_dir the system temp directory is used, which
            // is what makes the assertion above about the ini value.
            $withoutUploadDir = self::runInSubprocess(['-d', 'upload_tmp_dir=', '-r', $script]);
            $this->assertNotSame($withUploadDir, $withoutUploadDir);
            $this->assertSame((string) \realpath(\sys_get_temp_dir()) . \DIRECTORY_SEPARATOR, $withoutUploadDir);
        } finally {
            \rmdir($uploadDir);
        }
    }

    /**
     * Run PHP with the given arguments and return its trimmed stdout, or null
     * when the process could not be started.
     *
     * @param array<string> $args Arguments after the interpreter path.
     */
    private static function runInSubprocess(array $args): ?string
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = [];

        $proc = \proc_open([\PHP_BINARY, ...$args], $descriptors, $pipes);
        if (!\is_resource($proc)) {
            return null;
        }

        $out = '';
        foreach ($pipes as $index => $pipe) {
            if (!\is_resource($pipe)) {
                continue;
            }

            if ($index === 1) {
                $out = (string) \stream_get_contents($pipe);
            }

            \fclose($pipe);
        }

        \proc_close($proc);

        return \trim($out);
    }

    /**
     * A path naming a stream wrapper is refused and the default is used
     * instead: the cache directory is concatenated with generated names and
     * scanned with scandir(), neither of which is meaningful for a wrapper.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSetCachePathRejectsAStreamWrapper(): void
    {
        $cache = $this->getTestObject();
        $default = $cache->getCachePath();

        // 'file://' names a real, writable, existing directory through a
        // wrapper, so only the '://' test can refuse it.
        $cache->setCachePath('file://' . \sys_get_temp_dir());

        $this->assertSame($default, $cache->getCachePath());
        $this->assertStringNotContainsString('://', $cache->getCachePath());
    }

    /**
     * deleteOlderThan() removes what is strictly older than the cutoff, so an
     * entry whose mtime lands exactly on it survives.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteOlderThanKeepsAnEntryExactlyOnTheCutoff(): void
    {
        $cache = $this->getTestObject();

        $onCutoff = $cache->getNewFileName('age', 'on');
        $justOlder = $cache->getNewFileName('age', 'older');

        $age = 100;
        $now = \time();

        // The cutoff is time() - $age; the guard is `mtime < cutoff`.
        $this->assertTrue(\touch($onCutoff, $now - $age));
        $this->assertTrue(\touch($justOlder, $now - $age - 1));
        \clearstatcache();

        try {
            $cache->deleteOlderThan($age);

            $this->assertFileExists($onCutoff);
            $this->assertFileDoesNotExist($justOlder);
        } finally {
            if (\file_exists($onCutoff)) {
                \unlink($onCutoff);
            }

            if (\file_exists($justOlder)) {
                \unlink($justOlder);
            }
        }
    }

    /**
     * A name collision is recovered from: the next candidate is tried and
     * returned, and the entry that blocked the first one is left alone.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetNewFileNameRetriesAfterACollision(): void
    {
        $cache = new class('retry' . \uniqid('', false)) extends \Com\Tecnick\File\Cache {
            /**
             * Collide once, then yield a free name, so the loop has to come back
             * around for the file it finally returns.
             */
            private int $calls = 0;

            protected function randomToken(int $bytes): string
            {
                // The prefix is drawn from this method too, before the counter
                // is of interest: only the getNewFileName() suffixes are pinned.
                if ($this->prefix === '') {
                    return parent::randomToken($bytes);
                }

                $this->calls++;

                return $this->calls === 1 ? 'collide' : 'fresh';
            }

            public function cacheDir(): string
            {
                return $this->path;
            }
        };

        $blocker = $cache->cacheDir() . $cache->getFilePrefix() . 'typ_key_collide';
        $this->assertNotFalse(\file_put_contents($blocker, 'PRECIOUS'));

        try {
            $name = $cache->getNewFileName('typ', 'key');

            // The second candidate is what came back, not the first.
            $this->assertSame($cache->cacheDir() . $cache->getFilePrefix() . 'typ_key_fresh', $name);
            $this->assertFileExists($name);

            // The blocking entry was neither returned nor overwritten.
            $this->assertSame('PRECIOUS', \file_get_contents($blocker));

            \unlink($name);
        } finally {
            \unlink($blocker);
        }
    }

    /**
     * The filesystem-warning suppressor swallows E_WARNING and E_NOTICE only.
     * Every other level, the E_USER_* family included, still reaches the
     * handler the application installed.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSuppressionDoesNotDetachTheApplicationErrorHandler(): void
    {
        $suppress = new \ReflectionMethod(\Com\Tecnick\File\Cache::class, 'withoutFsWarnings');
        $cache = $this->getTestObject();

        $seen = [];
        \set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
            $seen[] = [$errno, $errstr];
            return true;
        });

        try {
            /** @var string $result */
            $result = $suppress->invoke($cache, static function (): string {
                \trigger_error('a warning', E_USER_WARNING);
                \trigger_error('a notice', E_USER_NOTICE);
                \trigger_error('a deprecation', E_USER_DEPRECATED);

                return 'done';
            });
        } finally {
            \restore_error_handler();
        }

        $this->assertSame('done', $result);
        $this->assertSame(
            [
                [E_USER_WARNING,    'a warning'],
                [E_USER_NOTICE,     'a notice'],
                [E_USER_DEPRECATED, 'a deprecation'],
            ],
            $seen,
        );
    }

    /**
     * With no application handler installed the same diagnostic falls through to
     * PHP's own, which is what returning false from the inner handler asks for.
     * The level is excluded from error_reporting() so nothing is printed.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSuppressionFallsThroughWhenNoHandlerIsInstalled(): void
    {
        $suppress = new \ReflectionMethod(\Com\Tecnick\File\Cache::class, 'withoutFsWarnings');
        $cache = $this->getTestObject();

        $level = \error_reporting(E_ALL & ~E_USER_DEPRECATED);
        \set_error_handler(null);

        // PHP's own handler records the diagnostic in error_get_last(), while
        // a swallowed one leaves this sentinel as the last error.
        \trigger_error('SENTINEL-BEFORE', E_USER_DEPRECATED);

        try {
            /** @var string $result */
            $result = $suppress->invoke($cache, static function (): string {
                \trigger_error('nobody is listening', E_USER_DEPRECATED);

                return 'done';
            });
        } finally {
            \restore_error_handler();
            \error_reporting($level);
        }

        $this->assertSame('done', $result);

        $last = \error_get_last();
        $this->assertIsArray($last);
        $this->assertSame('nobody is listening', $last['message']);
    }
}
