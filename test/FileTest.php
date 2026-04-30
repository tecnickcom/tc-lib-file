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
    /**
     * Port the local PHP built-in HTTP server is listening on (0 = not started).
     */
    private static int $serverPort = 0;

    /**
     * Process handle returned by proc_open() for the built-in server.
     *
     * @var resource|null
     */
    private static mixed $serverProcess = null;

    /**
     * Start a local PHP built-in HTTP server so that cURL tests can reach
     * a real HTTP endpoint without requiring external network access.
     */
    public static function setUpBeforeClass(): void
    {
        // Ensure FORCE_CURL is defined so that getUrlData() proceeds even
        // when allow_url_fopen is enabled in the test environment.
        if (! \defined('FORCE_CURL')) {
            \define('FORCE_CURL', true);
        }

        if (! \function_exists('curl_init')) {
            return;
        }

        // Find a free TCP port by binding to port 0 and reading the assignment.
        $sock = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return;
        }

        $name = (string) \stream_socket_get_name($sock, false);
        \fclose($sock);
        \preg_match('/(\d+)$/', $name, $matches);
        self::$serverPort = (int) ($matches[1] ?? 0);

        if (self::$serverPort === 0) {
            return;
        }

        $docRoot = __DIR__ . '/http';
        $cmd = \sprintf(
            'php -S 127.0.0.1:%d -t %s',
            self::$serverPort,
            \escapeshellarg($docRoot)
        );

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = \proc_open($cmd, $descriptors, $serverPipes);
        if ($proc === false) {
            self::$serverPort = 0;
            return;
        }

        foreach ($serverPipes as $pipe) {
            \fclose($pipe);
        }

        self::$serverProcess = $proc;

        // Wait until the server is accepting connections (up to 5 s).
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $conn = @\fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
            if ($conn !== false) {
                \fclose($conn);
                $ready = true;
                break;
            }

            \usleep(100_000);
        }

        if (! $ready) {
            \proc_terminate($proc);
            \proc_close($proc);
            self::$serverProcess = null;
            self::$serverPort = 0;
        }
    }

    /**
     * Shut down the local HTTP server started in setUpBeforeClass().
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            \proc_terminate(self::$serverProcess);
            \proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }

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
        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $handle = \fopen(__FILE__, 'rb');
        $this->assertNotFalse($handle);
        // length 0: the while-loop condition (0 < 0) is immediately false,
        // so $data stays empty and FileException is thrown.
        /**
         * @psalm-suppress InvalidArgument Intentionally passing 0 to exercise edge case
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
        $testObj->allowedHosts = ['localhost'];
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

    public function testGetAltUrlFromPathSpoofedScriptUriRejected(): void
    {
        $testObj = $this->getTestObject();
        // No allowedHosts configured → deny SCRIPT_URI host.
        $_SERVER['SCRIPT_URI'] = 'https://attacker.internal/app/script.php';

        $rfm = new \ReflectionMethod($testObj, 'getAltUrlFromPath');
        $rfm->setAccessible(true);

        $input = 'data/file.txt';
        $result = $rfm->invoke($testObj, $input);
        $this->assertSame($input, $result, 'Spoofed SCRIPT_URI host must not be used to build a URL');
    }

    public function testGetAltUrlFromPathAllowlistedScriptUriAccepted(): void
    {
        $testObj = $this->getTestObject();
        $testObj->allowedHosts = ['myapp.example.com'];
        $_SERVER['SCRIPT_URI'] = 'https://myapp.example.com/app/script.php';

        $rfm = new \ReflectionMethod($testObj, 'getAltUrlFromPath');
        $rfm->setAccessible(true);

        $result = $rfm->invoke($testObj, 'data/file.txt');
        $this->assertSame('https://myapp.example.com/data/file.txt', $result);
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
        $file->fileGetContents('http://www.example.com/test.txt');
    }

    public function testMaxRemoteSizeDefault(): void
    {
        $file = $this->getTestObject();
        $this->assertSame(52428800, $file->maxRemoteSize);
    }

    public function testMaxRemoteSizeConfigurable(): void
    {
        $file = $this->getTestObject();
        $file->maxRemoteSize = 1048576; // 1MB
        $this->assertSame(1048576, $file->maxRemoteSize);
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

    // -------------------------------------------------------------------------
    // Issue 1: CURLOPT_REDIR_PROTOCOLS is present in CURLOPT_DEFAULT
    // -------------------------------------------------------------------------

    public function testCurlOptRedirProtocolsPresent(): void
    {
        $ref = new \ReflectionClassConstant(\Com\Tecnick\File\File::class, 'CURLOPT_DEFAULT');
        /** @var array<int, mixed> $opts */
        $opts = $ref->getValue();
        $this->assertArrayHasKey(CURLOPT_REDIR_PROTOCOLS, $opts);
        // Only HTTP/HTTPS allowed for redirects — no FTP.
        $this->assertSame(
            CURLPROTO_HTTPS | CURLPROTO_HTTP,
            $opts[CURLOPT_REDIR_PROTOCOLS]
        );
    }

    // -------------------------------------------------------------------------
    // Issue 7: TLS verification flags pinned in fixed options
    // -------------------------------------------------------------------------

    public function testSslVerificationPinnedInFixedOptions(): void
    {
        $ref = new \ReflectionClassConstant(\Com\Tecnick\File\File::class, 'CURLOPT_FIXED');
        /** @var array<int, mixed> $opts */
        $opts = $ref->getValue();
        // SSL verification must be pinned in FIXED to prevent override
        $this->assertArrayHasKey(CURLOPT_SSL_VERIFYHOST, $opts);
        $this->assertArrayHasKey(CURLOPT_SSL_VERIFYPEER, $opts);
        // Verify strict verification is enforced
        $this->assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
        $this->assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
    }

    public function testSslVerificationCannotBeOverriddenByCustomOptions(): void
    {
        $testObj = $this->getTestObject();
        // Set custom curl options that try to disable SSL verification
        $testObj->curlopts = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];

        // Get the fixed options to verify they are unaffected
        $refProperty = new \ReflectionProperty($testObj, 'fixedCurlOpts');
        $refProperty->setAccessible(true);
        /** @var array<int, mixed> $fixedOpts */
        $fixedOpts = $refProperty->getValue($testObj);

        // Verify fixed options still have strict verification enabled
        // (they should override any custom options due to merge order in getUrlData)
        $this->assertSame(2, $fixedOpts[CURLOPT_SSL_VERIFYHOST]);
        $this->assertTrue($fixedOpts[CURLOPT_SSL_VERIFYPEER]);
    }

    // -------------------------------------------------------------------------
    // Issue 3: validateHost() / HTTP_HOST SSRF protection
    // -------------------------------------------------------------------------

    public function testSpoofedHttpHostIsRejectedByDefault(): void
    {
        $testObj = $this->getTestObject();
        // No allowedHosts configured → every host is denied.
        $_SERVER['HTTP_HOST'] = 'evil.internal';
        $_SERVER['HTTPS'] = 'on';

        $rfm = new \ReflectionMethod($testObj, 'getAltMissingUrlProtocol');
        $rfm->setAccessible(true);

        $input = '//evil.internal/steal';
        $result = $rfm->invoke($testObj, $input);
        // Without a trusted host the path must come back unchanged (decoded only).
        $this->assertSame(
            \htmlspecialchars_decode($input),
            $result,
            'Spoofed HTTP_HOST must not be used to build a URL'
        );
    }

    public function testAllowlistedHttpHostIsAccepted(): void
    {
        $testObj = $this->getTestObject();
        $testObj->allowedHosts = ['myapp.example.com'];
        $_SERVER['HTTP_HOST'] = 'myapp.example.com';
        $_SERVER['HTTPS'] = 'on';

        $rfm = new \ReflectionMethod($testObj, 'getAltMissingUrlProtocol');
        $rfm->setAccessible(true);

        $result = $rfm->invoke($testObj, '//myapp.example.com/path/file.txt');
        $this->assertSame('https://myapp.example.com/path/file.txt', $result);
    }

    public function testGetAltPathFromUrlSpoofedHostRejected(): void
    {
        $testObj = $this->getTestObject();
        // No allowedHosts → deny.
        $_SERVER['HTTP_HOST'] = 'attacker.internal';
        $_SERVER['DOCUMENT_ROOT'] = '/var/www';
        $_SERVER['HTTPS'] = 'off';

        $rfm = new \ReflectionMethod($testObj, 'getAltPathFromUrl');
        $rfm->setAccessible(true);

        $url = 'http://attacker.internal/secret';
        $result = $rfm->invoke($testObj, $url);
        $this->assertSame($url, $result, 'Spoofed host must not be used to build a local path');
    }

    // -------------------------------------------------------------------------
    // Issue 5: FTP protocols no longer supported
    // -------------------------------------------------------------------------

    public function testHasForbiddenProtocolFtp(): void
    {
        $file = $this->getTestObject();
        $this->assertTrue($file->hasForbiddenProtocol('ftp://example.com/file.txt'));
        $this->assertTrue($file->hasForbiddenProtocol('ftps://example.com/file.txt'));
    }

    // -------------------------------------------------------------------------
    // Issue 8: iterative rfRead — single-byte chunk delivery
    // -------------------------------------------------------------------------

    public function testRfReadSingleByteChunks(): void
    {
        $wrapperName = 'tcsinglebyte';
        if (! \in_array($wrapperName, \stream_get_wrappers(), true)) {
            \stream_wrapper_register($wrapperName, SingleByteStreamWrapper::class);
        }

        $file = $this->getTestObject();
        $handle = \fopen($wrapperName . '://data', 'rb');
        $this->assertNotFalse($handle);

        try {
            $res = $file->rfRead($handle, 4);
            $this->assertSame('abcd', $res);
        } finally {
            \fclose($handle);
            \stream_wrapper_unregister($wrapperName);
        }
    }

    // -------------------------------------------------------------------------
    // rfRead inner break: fread returns '' before feof signals end-of-stream
    // -------------------------------------------------------------------------

    public function testRfReadBreakOnEmptyChunk(): void
    {
        $wrapperName = 'tcemptyread';
        if (! \in_array($wrapperName, \stream_get_wrappers(), true)) {
            \stream_wrapper_register($wrapperName, EmptyReadStreamWrapper::class);
        }

        $file = $this->getTestObject();
        $handle = \fopen($wrapperName . '://data', 'rb');
        $this->assertNotFalse($handle);

        try {
            // The wrapper returns 'ab' on the first read then '' forever while
            // stream_eof() never returns true, so rfRead() hits the inner break
            // (File.php line 194) after the second fread() call.
            $res = $file->rfRead($handle, 10);
            $this->assertSame('ab', $res);
        } finally {
            \fclose($handle);
            \stream_wrapper_unregister($wrapperName);
        }
    }

    // -------------------------------------------------------------------------
    // Progress callback direct-invocation tests (cURL size-limit logic)
    // -------------------------------------------------------------------------

    public function testProgressCallbackReturnsZeroBelowLimit(): void
    {
        $file = $this->getTestObject();
        $file->maxRemoteSize = 100;

        $rfm = new \ReflectionMethod($file, 'createProgressCallback');
        $rfm->setAccessible(true);

        $bytesRead = 0;
        $args = [&$bytesRead];
        $callback = $rfm->invokeArgs($file, $args);
        \assert(\is_callable($callback));

        // 50 bytes downloaded — well below the 100-byte limit → return 0
        $result = $callback(null, 50, 50, 0, 0);
        $this->assertSame(0, $result);
    }

    public function testProgressCallbackReturnsAbortSignalAboveLimit(): void
    {
        $file = $this->getTestObject();
        $file->maxRemoteSize = 100;

        $rfm = new \ReflectionMethod($file, 'createProgressCallback');
        $rfm->setAccessible(true);

        $bytesRead = 0;
        $args = [&$bytesRead];
        $callback = $rfm->invokeArgs($file, $args);
        \assert(\is_callable($callback));

        // 200 bytes downloaded — exceeds the 100-byte limit → return 1 (abort)
        $result = $callback(null, 200, 200, 0, 0);
        $this->assertSame(1, $result);
    }

    // -------------------------------------------------------------------------
    // Local HTTP server tests — cURL size-limit enforcement and return value
    // -------------------------------------------------------------------------

    public function testGetUrlDataSizeExceeded(): void
    {
        if (self::$serverPort === 0 || ! \function_exists('curl_init')) {
            $this->markTestSkipped('Local HTTP server not available');
        }

        $file = $this->getTestObject();
        // Set a very small limit so the 1 000-byte response from large.php
        // triggers CURLE_ABORTED_BY_CALLBACK (errno 42).
        $file->maxRemoteSize = 10;

        $this->bcExpectException('\\' . \Com\Tecnick\File\Exception::class);
        $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/large.php');
    }

    public function testGetUrlDataReturnTrue(): void
    {
        if (self::$serverPort === 0 || ! \function_exists('curl_init')) {
            $this->markTestSkipped('Local HTTP server not available');
        }

        // Create a File instance with no fixed cURL options so that
        // CURLOPT_RETURNTRANSFER is not set.  curl_exec() then returns true
        // on success, exercising the `$ret === true ? '' : $ret` branch.
        $file = new \Com\Tecnick\File\File([], []);

        \ob_start();
        $result = $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/empty.php');
        \ob_end_clean();

        $this->assertSame('', $result);
    }
}
