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
 * @link      https://github.com/tecnickcom/tc-lib-pdf-filecache
 *
 * This file is part of tc-lib-pdf-filecache software library.
 */

namespace Test;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Unit Test
 *
 * @since     2011-05-23
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-filecache
 */
class CacheTest extends TestUtil
{
    /**
     * Build a cache instance with a prefix unique to this test run.
     *
     * delete() and deleteOlderThan() act on every file carrying the instance
     * prefix in the shared system temp directory, so a fixed prefix would make
     * two concurrent runs delete each other's files and silently sweep up
     * debris left by an earlier crashed run.
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
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetFilePrefixSanitizesUnsafeCharacters(): void
    {
        // '+' and '/' map to '-' and '_'; everything outside [A-Za-z0-9_-] is
        // dropped. No file is created, so a fixed prefix is safe here.
        $cache = new \Com\Tecnick\File\Cache('1_2-a+B/c');
        $this->assertSame('_1_2-a-B_c_', $cache->getFilePrefix());
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
     * An unresolvable cache directory must be reported, not accepted as ''.
     * An empty path makes tempnam() fall back to the system temp directory
     * while delete() scans the working directory, so files would be written
     * and searched for in two different places with no error.
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
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf[' . \uniqid('', false) . ']*?';
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
     * @throws \Com\Tecnick\File\Exception
     */
    public function testKeyOnlyDeletesAll(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('foo', 'bar');
        \file_put_contents($file, '');
        $this->assertTrue(\file_exists($file));

        try {
            // key-only call should treat as delete all
            $cache->delete(null, 'bar');
            $this->assertFalse(\file_exists($file));
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
    // Issue 4: glob-injection sanitisation in delete()
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
            // Call delete() with glob metacharacters in $type — must not expand.
            $cache->delete('*', null);

            // The real file must still exist because '*' was stripped to '' and
            // the resulting prefix matched nothing (or only unrelated files).
            // If metacharacter injection were possible every file would be gone.
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
            // Inject a glob metacharacter in $key — must be stripped.
            $cache->delete('mytype', '?');

            $this->assertTrue(\file_exists($real), 'Metacharacters in $key must not delete unrelated files');
        } finally {
            $cache->delete();
        }
    }

    // -------------------------------------------------------------------------
    // Issue 6: per-instance cache properties
    // -------------------------------------------------------------------------

    // Testing covered by testEachInstanceHasOwnPrefix() and testEachInstanceHasOwnCachePath()

    // -------------------------------------------------------------------------
    // Issue 9: createNewFileName()
    // -------------------------------------------------------------------------

    // Testing the exception path of getNewFileName() requires tempnam() to return false,
    // which is not deterministic in this environment because tempnam() can fall back
    // to the system temporary directory.

    // -------------------------------------------------------------------------
    // Issue 10: deleteOlderThan()
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteOlderThanNoFiles(): void
    {
        // Call deleteOlderThan() when no files exist for this cache prefix, so
        // the empty-result early return is exercised.
        $cache = new \Com\Tecnick\File\Cache('emptyprefix' . \uniqid('', false));
        $cache->deleteOlderThan(3600);
        // No exception thrown is the expected outcome.
        $this->expectNotToPerformAssertions();
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeleteNoFiles(): void
    {
        $cache = new \Com\Tecnick\File\Cache('emptyprefix' . \uniqid('', false));
        $cache->delete();
        $this->expectNotToPerformAssertions();
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
     * When the host application defines K_PATH_CACHE without a trailing separator,
     * the fallback path must still be normalized so generated files land inside the
     * cache directory instead of escaping into its parent (e.g. ".../cache" + name
     * yielding ".../cache_<name>"). Runs in a separate process because K_PATH_CACHE
     * is a constant that cannot be (re)defined once the cache class has set it.
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
}
