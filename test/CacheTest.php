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
        $this->assertNotFalse($val);
        $this->bcAssertMatchesRegularExpression('/_1_2-a-B_c_tst_0123_/', $val);
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
                $this->assertNotFalse($file[$idk]);
                \file_put_contents($file[$idk], '');
                $this->assertTrue(\file_exists($file[$idk]));
                ++$idk;
            }
        }

        // delete a specific type/key pair
        $cache->delete('2', '1');
        $this->assertNotFalse($file[2]);
        $this->assertFalse(\file_exists($file[2]));

        // delete all entries for type "1"
        $cache->delete('1');
        $this->assertNotFalse($file[0]);
        $this->assertFalse(\file_exists($file[0]));
        $this->assertNotFalse($file[1]);
        $this->assertFalse(\file_exists($file[1]));
        $this->assertNotFalse($file[3]);
        $this->assertTrue(\file_exists($file[3]));

        // delete everything
        $cache->delete();
        $this->assertFalse(\file_exists($file[3]));
    }

    public function testKeyOnlyDeletesAll(): void
    {
        $cache = $this->getTestObject();
        $file = $cache->getNewFileName('foo', 'bar');
        $this->assertNotFalse($file);
        $this->assertIsString($file);
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
        $this->assertNotFalse($file);
        $this->assertIsString($file);
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

    public function testSharedPrefixAndDeleteBehavior(): void
    {
        // prefix is stored in a static property, so the last-constructed
        // instance determines the value used by all objects
        $cache1 = new \Com\Tecnick\File\Cache('pfx1');
        $this->assertStringContainsString('pfx1', $cache1->getFilePrefix());

        // constructing a second cache changes the static prefix
        $cache2 = new \Com\Tecnick\File\Cache('pfx2');

        // both objects now report the same prefix and it contains "pfx2"
        $this->assertSame($cache1->getFilePrefix(), $cache2->getFilePrefix());
        $this->assertStringContainsString('pfx2', $cache1->getFilePrefix());

        // since the prefix is shared, files generated by either instance use the
        // same pattern
        $file1 = $cache1->getNewFileName('t', 'k');
        $file2 = $cache2->getNewFileName('t', 'k');
        $this->assertNotFalse($file1);
        $this->assertIsString($file1);
        $this->assertNotFalse($file2);
        $this->assertIsString($file2);

        \file_put_contents($file1, '');
        \file_put_contents($file2, '');

        $this->assertTrue(\file_exists($file1));
        $this->assertTrue(\file_exists($file2));

        // deleting via one instance removes both files because they share the
        // same prefix pattern
        $cache1->delete('t', 'k');
        $this->assertFalse(\file_exists($file1));
        $this->assertFalse(\file_exists($file2));
    }
}
