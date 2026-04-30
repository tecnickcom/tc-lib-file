<?php

/**
 * CacheTest.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-filecache
 *
 * This file is part of tc-lib-pdf-filecache software library.
 */

namespace Test;

/**
 * Unit Test
 *
 * @since     2011-05-23
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-filecache
 */
class CacheTest extends TestUtil
{
    protected function getTestObject(): \Com\Tecnick\File\Cache
    {
        return new \Com\Tecnick\File\Cache('1_2-a+B/c');
    }

    public function testAutoPrefix(): void
    {
        $cache = new \Com\Tecnick\File\Cache();
        $this->assertNotEmpty($cache->getFilePrefix());
    }

    public function testGetCachePath(): void
    {
        $cache = $this->getTestObject();
        $cachePath = $cache->getCachePath();
        $this->assertEquals('/', $cachePath[0]);
        $this->assertEquals('/', \substr($cachePath, -1));

        $cache->setCachePath();
        $this->assertEquals($cachePath, $cache->getCachePath());

        $path = '/tmp';
        $cache->setCachePath($path);
        $this->assertEquals('/tmp/', $cache->getCachePath());
    }

    public function testGetFilePrefix(): void
    {
        $cache = $this->getTestObject();
        $filePrefix = $cache->getFilePrefix();
        $this->assertEquals('_1_2-a-B_c_', $filePrefix);
    }

    public function testGetNewFileName(): void
    {
        $cache = $this->getTestObject();
        $val = $cache->getNewFileName('tst', '0123');
        $this->bcAssertMatchesRegularExpression('/_1_2-a-B_c_tst_0123_/', $val);
        \unlink($val);
    }

    public function testNormalizePathInvalid(): void
    {
        $cache = $this->getTestObject();

        // invoke protected normalizePath via reflection so we can test
        // the branch where realpath() returns false
        $ref = new \ReflectionMethod($cache, 'normalizePath');
        $ref->setAccessible(true);

        $invalid = \sys_get_temp_dir() . '/nonexistent_' . \uniqid('', true);
        $this->assertFalse(\file_exists($invalid), 'Sanity check: path should not exist');

        $this->assertSame('', $ref->invoke($cache, $invalid));
    }

    public function testDelete(): void
    {
        $cache = $this->getTestObject();
        $idk = 0;
        for ($idx = 1; $idx <= 2; ++$idx) {
            for ($idy = 1; $idy <= 2; ++$idy) {
                $file[$idk] = $cache->getNewFileName((string) $idx, (string) $idy);
                \file_put_contents($file[$idk], '');
                $this->assertTrue(\file_exists($file[$idk]));
                ++$idk;
            }
        }

        // delete a specific type/key pair
        $cache->delete('2', '1');
        $this->assertFalse(\file_exists($file[2]));

        // delete all entries for type "1"
        $cache->delete('1');
        $this->assertFalse(\file_exists($file[0]));
        $this->assertFalse(\file_exists($file[1]));
        $this->assertTrue(\file_exists($file[3]));

        // delete everything
        $cache->delete();
        $this->assertFalse(\file_exists($file[3]));
    }

    public function testKeyOnlyDeletesAll(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('foo', 'bar');
        \file_put_contents($file, '');
        $this->assertTrue(\file_exists($file));

        // key-only call should treat as delete all
        $cache->delete(null, 'bar');
        $this->assertFalse(\file_exists($file));
    }

    public function testDeleteNonExistingPatterns(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('foo', 'bar');
        \file_put_contents($file, '');
        $this->assertTrue(\file_exists($file));

        // deleting a type that does not exist should leave the file in place
        $cache->delete('no-such-type');
        $this->assertTrue(\file_exists($file));

        // deleting a non-existent key under existing type
        $cache->delete('foo', 'no-such-key');
        $this->assertTrue(\file_exists($file));

        \unlink($file);
    }

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

    public function testEachInstanceHasOwnCachePath(): void
    {
        $cache1 = new \Com\Tecnick\File\Cache();
        $path1 = $cache1->getCachePath();

        $tempdir = \sys_get_temp_dir() . '/cache_test_' . \uniqid();
        \mkdir($tempdir);

        $cache2 = new \Com\Tecnick\File\Cache();
        $cache2->setCachePath($tempdir);
        $path2 = $cache2->getCachePath();

        // Paths should be different for each instance
        $this->assertNotSame($path1, $path2);
        $this->assertStringContainsString('cache_test_', $path2);

        \rmdir($tempdir);
    }

    // -------------------------------------------------------------------------
    // Issue 4: glob-injection sanitisation in delete()
    // -------------------------------------------------------------------------

    public function testDeleteGlobCharsInTypeSanitised(): void
    {
        $cache = new \Com\Tecnick\File\Cache('safepfx');

        // Create a real file we do NOT want deleted.
        $real = $cache->getNewFileName('safe', '1');
        \file_put_contents($real, '');
        $this->assertTrue(\file_exists($real));

        // Call delete() with glob metacharacters in $type — must not expand.
        $cache->delete('*', null);

        // The real file must still exist because '*' was stripped to '' and
        // the resulting pattern matched nothing (or only unrelated files).
        // If glob injection were possible every file would be gone.
        $this->assertTrue(\file_exists($real), 'Glob injection via $type must not delete unrelated files');
        \unlink($real);
    }

    public function testDeleteGlobCharsInKeySanitised(): void
    {
        $cache = new \Com\Tecnick\File\Cache('safepfx2');

        $real = $cache->getNewFileName('mytype', 'goodkey');
        \file_put_contents($real, '');
        $this->assertTrue(\file_exists($real));

        // Inject glob metacharacter in $key — must be stripped.
        $cache->delete('mytype', '?');

        $this->assertTrue(\file_exists($real), 'Glob injection via $key must not delete unrelated files');
        \unlink($real);
    }

    // -------------------------------------------------------------------------
    // Issue 6: per-instance cache properties
    // -------------------------------------------------------------------------

    // Testing covered by testEachInstanceHasOwnPrefix() and testEachInstanceHasOwnCachePath()

    // -------------------------------------------------------------------------
    // Issue 9: createNewFileName()
    // -------------------------------------------------------------------------

    // Testing the exception path of getNewFileName() requires tempnam() to return false,
    // which cannot be triggered reliably in PHP because tempnam() falls back to the
    // system temporary directory. The exception path is covered by code inspection only.

    // -------------------------------------------------------------------------
    // Issue 10: deleteOlderThan()
    // -------------------------------------------------------------------------

    public function testDeleteOlderThanNoFiles(): void
    {
        // Call deleteOlderThan() when no files exist for this cache prefix.
        // glob() returns [] so the early-return on line 171 is exercised.
        $cache = new \Com\Tecnick\File\Cache('emptyprefix_' . \uniqid('', true));
        $cache->deleteOlderThan(3600);
        // No exception thrown is the expected outcome.
        $this->expectNotToPerformAssertions();
    }

    public function testDeleteOlderThan(): void
    {
        $cache = new \Com\Tecnick\File\Cache('ttl');

        $old = $cache->getNewFileName('aged', '1');
        $fresh = $cache->getNewFileName('aged', '2');
        \file_put_contents($old, '');
        \file_put_contents($fresh, '');

        // Back-date the "old" file to 2 hours ago.
        \touch($old, \time() - 7200);

        // Delete files older than 1 hour.
        $cache->deleteOlderThan(3600);

        $this->assertFalse(\file_exists($old), 'Expired file must be deleted');
        $this->assertTrue(\file_exists($fresh), 'Fresh file must be kept');

        \unlink($fresh);
    }
}
