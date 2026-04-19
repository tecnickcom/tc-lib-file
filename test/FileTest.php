<?php

/**
 * FileTest.php
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-file
 *
 * This file is part of tc-lib-file software library.
 */

namespace Test;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * File Color class test
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
class FileTest extends TestUtil
{
    protected function getTestObject(): \Com\Tecnick\File\File
    {
        return new \Com\Tecnick\File\File();
    }

    public function testFopenLocal(): void
    {
        $file = $this->getTestObject();
        $handle = $file->fopenLocal(__FILE__, 'r');
        $this->bcAssertIsResource($handle);
        \fclose($handle);
    }

    public function testFopenLocalNonLocal(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fopenLocal('http://www.example.com/test.txt', 'r');
    }

    public function testFopenLocalMissing(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fopenLocal('/missing_error.txt', 'r');
    }

    public function testFopenLocalDoubleDot(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fopenLocal('/tmp/invalid/../test.txt', 'r');
    }

    public function testfReadInt(): void
    {
        $file = $this->getTestObject();
        $handle = \fopen(__FILE__, 'r');
        $this->assertNotFalse($handle);
        $res = $file->fReadInt($handle);
        // '<?ph' = 60 63 112 104 = 00111100 00111111 01110000 01101000 = 1010790504
        $this->assertEquals(1_010_790_504, $res);
        \fclose($handle);
    }

    public function testfReadIntReadFailureException(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();

        $tmp = \tempnam(\sys_get_temp_dir(), 'tc');
        $handle = @\fopen($tmp, 'w');
        $this->assertNotFalse($handle);
        @$file->fReadInt($handle);
        \fclose($handle);
        \unlink($tmp);
    }

    public function testRfRead(): void
    {
        $file = $this->getTestObject();
        $handle = \fopen(\dirname(__DIR__) . '/src/File.php', 'rb');
        $this->assertNotFalse($handle);
        $res = $file->rfRead($handle, 2);
        $this->assertEquals('<?', $res);
        $res = $file->rfRead($handle, 3);
        $this->assertEquals('php', $res);
        \fclose($handle);
    }

    public function testRfReadException(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->rfRead(null, 2);
    }

    public function testRfReadClosedHandleException(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $handle = \fopen(__FILE__, 'rb');
        // ensure static analyzers know fopen succeeded
        $this->assertNotFalse($handle);
        \assert(\is_resource($handle));

        \fclose($handle);
        // handle is still typed resource by analyzers even after close
        $file->rfRead($handle, 1);
    }

    public function testRfReadZeroLength(): void
    {
        $this->expectException(\ValueError::class);
        $file = $this->getTestObject();
        $handle = \fopen(__FILE__, 'rb');
        $this->assertNotFalse($handle);
        // length 0 is not allowed by PHP's fread and will raise ValueError
        /**
         * @psalm-suppress InvalidArgument Intentionally passing 0 to trigger ValueError
         * @phpstan-ignore-next-line
         */
        $file->rfRead($handle, 0);
        \fclose($handle);
    }

    public function testRfReadEofShorter(): void
    {
        $file = $this->getTestObject();
        $tmp = \tempnam(\sys_get_temp_dir(), 'tc');
        \file_put_contents($tmp, 'xy');
        $handle = \fopen($tmp, 'rb');
        $this->assertNotFalse($handle);
        $res = $file->rfRead($handle, 10);
        $this->assertEquals('xy', $res);
        \fclose($handle);
        \unlink($tmp);
    }

    public function testRfReadRecursiveBufferedStream(): void
    {
        if (! \in_array('tcreadpartial', \stream_get_wrappers(), true)) {
            \stream_wrapper_register('tcreadpartial', RecursiveReadStreamWrapper::class);
        }

        $file = new RecursiveReadFile();
        $handle = \fopen('tcreadpartial://buffered', 'rb');
        $this->assertNotFalse($handle);

        try {
            $res = $file->rfRead($handle, 5);
            $this->assertSame('abcde', $res);
        } finally {
            \fclose($handle);
            \stream_wrapper_unregister('tcreadpartial');
        }
    }

    public function testHasUnreadBytes(): void
    {
        $file = $this->getTestObject();
        $handle = \fopen(\dirname(__DIR__) . '/src/File.php', 'rb');
        $this->assertNotFalse($handle);

        try {
            $this->assertSame('<?', \fread($handle, 2));

            $rfm = new \ReflectionMethod($file, 'hasUnreadBytes');
            $rfm->setAccessible(true);

            $this->assertTrue($rfm->invoke($file, $handle));
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @param string $file     File path
     * @param array{string, array<int, string>}  $expected Expected result
     */
    #[DataProvider('getAltFilePathsDataProvider')]
    public function testGetAltFilePaths(string $file, array $expected): void
    {
        $testObj = $this->getTestObject();
        $_SERVER['DOCUMENT_ROOT'] = '/var/www';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_URI'] = 'https://localhost/path/example.php';
        $alt = $testObj->getAltFilePaths($file);
        $this->assertEquals($expected, $alt);
    }

    /**
     * Data provider for testGetAltFilePaths
     *
     * @return array<array{string, array<int, string>}>
     */
    public static function getAltFilePathsDataProvider(): array
    {
        return [
            [
                'http://www.example.com/test.txt',
                [
                    0 => 'http://www.example.com/test.txt',
                ],
            ],
            [
                'https://localhost/path/test.txt',
                [
                    0 => 'https://localhost/path/test.txt',
                    3 => '/var/www/path/test.txt',
                ],
            ],
            [
                '//www.example.com/space test.txt',
                [
                    0 => '//www.example.com/space test.txt',
                    2 => 'https://www.example.com/space%20test.txt',
                ],
            ],
            [
                '/path/test.txt',
                [
                    0 => '/path/test.txt',
                    1 => '/var/www/path/test.txt',
                    4 => 'https://localhost/path/test.txt',
                ],
            ],
            [
                'https://localhost/path/test.php?a=0&b=1&amp;c=2;&amp;d="a+b%20c"',
                [
                    0 => 'https://localhost/path/test.php?a=0&b=1&amp;c=2;&amp;d="a+b%20c"',
                    2 => 'https://localhost/path/test.php?a=0&b=1&c=2;&d="a+b%20c"',
                ],
            ],
            [
                'path/test.txt',
                [
                    0 => 'path/test.txt',
                    4 => 'https://localhost/path/test.txt',
                ],
            ],
        ];
    }

    /**
     * Ensure getAltUrlFromPath returns the input when SCRIPT_URI cannot be parsed
     * (covers the guarded return on line 363 of File.php).
     */
    public function testGetAltUrlFromPathUnparseableUri(): void
    {
        $testObj = $this->getTestObject();

        // set SCRIPT_URI to something parse_url will handle but without scheme/host
        $_SERVER['SCRIPT_URI'] = 'not-a-url';

        $rfm = new \ReflectionMethod($testObj, 'getAltUrlFromPath');
        $rfm->setAccessible(true);

        $input = 'some/path.txt';
        $result = $rfm->invoke($testObj, $input);
        $this->assertSame($input, $result, 'Expected original path when SCRIPT_URI lacks scheme/host');
    }

    public function testFileGetContentsMissingException(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fileGetContents('missing.txt');
    }

    public function testFileGetContentsDoubleDotException(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fileGetContents('/tmp/something/../test.txt');
    }

    public function testFileGetContentsForbiddenProtocolException(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fileGetContents('phar://test.txt');
    }

    public function testFileGetContents(): void
    {
        $file = $this->getTestObject();
        $res = $file->fileGetContents(__FILE__);
        $this->assertEquals('<?php', \substr($res, 0, 5));
    }

    public function testFileGetContentsCurl(): void
    {
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        \define('FORCE_CURL', true);
        $file->fileGetContents('http://www.example.com/test.txt');
    }

    public function testHasDoubleDots(): void
    {
        $file = $this->getTestObject();
        $res = $file->hasDoubleDots('/tmp/../test.txt');
        $this->assertTrue($res);
        $res = $file->hasDoubleDots('/tmp/test.txt');
        $this->assertFalse($res);
    }

    public function testHasForbiddenProtocol(): void
    {
        $file = $this->getTestObject();
        $res = $file->hasForbiddenProtocol('phar://test.txt');
        $this->assertTrue($res);
        $res = $file->hasForbiddenProtocol('http://www.example.com/test.txt');
        $this->assertFalse($res);
        $res = $file->hasForbiddenProtocol('file://some/file.txt');
        $this->assertFalse($res);
        $res = $file->hasForbiddenProtocol('./some/file.txt');
        $this->assertFalse($res);
    }
}
