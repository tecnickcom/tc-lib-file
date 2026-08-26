<?php

/**
 * FileTest.php
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
 * File class test
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
class FileTest extends TestUtil
{
    /**
     * Port the local PHP built-in HTTP server is listening on (0 = not started).
     */
    private static int $serverPort = 0;

    /**
     * Identity token the local server echoes from /identity.php.
     */
    private static string $serverMarker = '';

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
        if (!\function_exists('curl_init')) {
            return;
        }

        // TC_LIB_FILE_SKIP_HTTP_SERVER=1 skips the local server, so the tests
        // depending on it are reported as skipped.
        $skip = \getenv('TC_LIB_FILE_SKIP_HTTP_SERVER');
        if ($skip !== false && $skip !== '' && $skip !== '0') {
            return;
        }

        // proc_open() may be disabled via disable_functions in hardened setups.
        if (!\function_exists('proc_open')) {
            return;
        }

        // Find a free TCP port by binding to port 0 and reading the assignment.
        // A bind warning is suppressed; the false return is handled below.
        $errno = 0;
        $errstr = '';
        \set_error_handler(static fn(): bool => true);
        $sock = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        \restore_error_handler();
        if ($sock === false) {
            return;
        }

        $name = (string) \stream_socket_get_name($sock, false);
        \fclose($sock);
        $matches = [];
        \preg_match('/(\d+)$/', $name, $matches);
        $port = (int) ($matches[1] ?? 0);

        if ($port === 0) {
            return;
        }

        $docRoot = __DIR__ . '/http';

        // The command is an array so that proc_open() execs the binary
        // directly and proc_terminate() reaches it rather than a shell.
        // PHP_BINARY is the interpreter running this suite.
        $cmd = [\PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot];

        // Another process can claim the port between the probe above and the
        // bind, so the server is given a token that is checked below.
        $marker = \uniqid('tclf', true);
        self::$serverMarker = $marker;

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $serverPipes = [];
        \set_error_handler(static fn(): bool => true);
        $proc = \proc_open($cmd, $descriptors, $serverPipes, null, [
            ...\getenv(),
            'TC_LIB_FILE_SERVER_MARKER' => $marker,
        ]);
        \restore_error_handler();
        if (!\is_resource($proc)) {
            return;
        }

        foreach ($serverPipes as $pipe) {
            \fclose($pipe);
        }

        // Wait up to ~10 s for the server to serve its own token, and stop as
        // soon as the child has exited.
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $status = \proc_get_status($proc);
            if (!$status['running']) {
                break;
            }

            if (self::serverAnswersWithMarker($port, $marker)) {
                $ready = true;
                break;
            }

            \usleep(100_000);
        }

        if (!$ready) {
            self::terminateServer($proc);
            return;
        }

        self::$serverPort = $port;
        self::$serverProcess = $proc;
    }

    /**
     * Shut down the local HTTP server started in setUpBeforeClass().
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            self::terminateServer(self::$serverProcess);
            self::$serverProcess = null;
        }

        self::$serverPort = 0;
    }

    /**
     * Tell whether the server on $port is the one this class started.
     *
     * @param int    $port  Port to probe.
     * @param string $marker Identity marker the server was given.
     */
    private static function serverAnswersWithMarker(int $port, string $marker): bool
    {
        $errno = 0;
        $errstr = '';

        \set_error_handler(static fn(): bool => true);
        $conn = \fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        \restore_error_handler();

        if ($conn === false) {
            return false;
        }

        try {
            \stream_set_timeout($conn, 2);
            \fwrite($conn, "GET /identity.php HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

            $response = '';
            while (!\feof($conn)) {
                $chunk = \fread($conn, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $response .= $chunk;
            }
        } finally {
            \fclose($conn);
        }

        return \str_contains($response, ' 200 ') && \str_ends_with($response, $marker);
    }

    /**
     * Stop the spawned HTTP server without letting proc_close() block.
     *
     * The child gets a bounded window to exit on SIGTERM, then SIGKILL.
     *
     * @param resource $proc Process handle from proc_open().
     */
    private static function terminateServer(mixed $proc): void
    {
        if (!\is_resource($proc)) {
            return;
        }

        \proc_terminate($proc); // SIGTERM

        // proc_get_status() called immediately after proc_terminate() always
        // reports the child as still running, so the exit is polled for ~1 s.
        for ($i = 0; $i < 100; $i++) {
            if (!\proc_get_status($proc)['running']) {
                break;
            }

            \usleep(10_000);
        }

        if (\proc_get_status($proc)['running']) {
            \proc_terminate($proc, 9); // SIGKILL
        }

        \proc_close($proc);
    }

    /**
     * Snapshot of the $_SERVER entries the alt-path tests overwrite.
     *
     * Only string values are recorded: the alt-path helpers act on these keys
     * only when is_string() holds.
     *
     * @var array<string, string|null>
     */
    private array $serverBackup = [];

    /**
     * Keys of $_SERVER that individual tests set to drive the alt-path helpers.
     */
    private const SERVER_KEYS = ['DOCUMENT_ROOT', 'HTTP_HOST', 'HTTPS', 'SCRIPT_URI'];

    /**
     * Capture the request-metadata entries before each test.
     *
     * These entries are process-global, so they are restored in tearDown()
     * independently of the backupGlobals setting in phpunit.xml.dist.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SERVER_KEYS as $key) {
            $value = $_SERVER[$key] ?? null;
            $this->serverBackup[$key] = \is_string($value) ? $value : null;
        }
    }

    /**
     * Restore the request-metadata entries captured in setUp().
     */
    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
                continue;
            }

            $_SERVER[$key] = $value;
        }

        parent::tearDown();
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    protected function getTestObject(): \Com\Tecnick\File\File
    {
        return new \Com\Tecnick\File\File();
    }

    /**
     * Create a temporary directory and return its canonical path.
     *
     * sys_get_temp_dir() can report a path that realpath() rewrites (an 8.3
     * short name on Windows, the /var -> /private/var symlink on macOS), while
     * the allowlist stores the canonical form of each root.
     */
    private static function makeTempDir(): string
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        if (!\mkdir($dir, 0o777, true)) {
            self::fail('unable to create the temporary directory: ' . $dir);
        }

        $real = \realpath($dir);
        if ($real === false) {
            self::fail('unable to resolve the temporary directory: ' . $dir);
        }

        return $real;
    }

    /**
     * Run a callback with the expected filesystem warnings suppressed.
     *
     * symlink(), unlink() and rmdir() warn on an environment that refuses the
     * operation; the return value is what these tests act on.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    private static function withoutWarnings(callable $callback): mixed
    {
        \set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);

        try {
            return $callback();
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Remove a symlink.
     *
     * On Windows a symlink to a directory is a directory entry and needs
     * rmdir(); on POSIX unlink() removes either kind of symlink.
     *
     * @param string $path Path of the symlink to remove.
     */
    private static function removeSymlink(string $path): void
    {
        if (\DIRECTORY_SEPARATOR === '\\' && \is_dir($path)) {
            \rmdir($path);
            return;
        }

        \unlink($path);
    }

    /**
     * Create a symlink, reporting failure instead of warning.
     *
     * Windows without developer mode and some hardened containers refuse
     * symlink(); the caller skips the test in that case.
     *
     * @param string $target Existing path the link points to.
     * @param string $link   Path of the link to create.
     */
    private static function trySymlink(string $target, string $link): bool
    {
        \set_error_handler(static fn(): bool => true);

        try {
            return \symlink($target, $link);
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Skip a test that needs the local HTTP server, unless running on CI.
     *
     * On CI the remote-transfer tests fail instead of skipping, unless
     * TC_LIB_FILE_SKIP_HTTP_SERVER opts out of them.
     */
    private function requireLocalHttpServer(): void
    {
        if (self::$serverPort !== 0 && \function_exists('curl_init')) {
            return;
        }

        $ci = \getenv('CI');
        $optOut = \getenv('TC_LIB_FILE_SKIP_HTTP_SERVER');
        $optedOut = $optOut !== false && $optOut !== '' && $optOut !== '0';

        if ($ci !== false && $ci !== '' && $ci !== '0' && !$optedOut) {
            self::fail(
                'Local HTTP server not available on CI. The remote-transfer tests must run there; '
                . 'set TC_LIB_FILE_SKIP_HTTP_SERVER=1 to opt out deliberately.',
            );
        }

        $this->markTestSkipped('Local HTTP server not available');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFopenLocal(): void
    {
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);
        $handle = $file->fopenLocal(__FILE__, 'r');
        $this->assertIsResource($handle);
        \fclose($handle);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFopenLocalNonLocal(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fopenLocal('http://www.example.com/test.txt', 'r');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFopenLocalMissing(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fopenLocal('/missing_error.txt', 'r');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFopenLocalOpenFailureAfterValidation(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);
        $file->fopenLocal('/definitely-missing-' . \uniqid('', true) . '.txt', 'r');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFopenLocalDoubleDot(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fopenLocal(
            \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'invalid'
            . \DIRECTORY_SEPARATOR
            . '..'
            . \DIRECTORY_SEPARATOR
            . 'test.txt',
            'r',
        );
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testfReadInt(): void
    {
        $file = $this->getTestObject();
        $handle = \fopen(__FILE__, 'r');
        $this->assertNotFalse($handle);
        $res = $file->fReadInt($handle);
        // '<?ph' = 60 63 112 104 = 00111100 00111111 01110000 01101000 = 1010790504
        $this->assertSame(1_010_790_504, $res);
        \fclose($handle);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testfReadIntReadFailureException(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();

        $tmp = \tempnam(\sys_get_temp_dir(), 'tc');
        $this->assertNotFalse($tmp);
        $handle = \fopen($tmp, 'w');
        $this->assertNotFalse($handle);

        try {
            $file->fReadInt($handle);
        } finally {
            \fclose($handle);
            \unlink($tmp);
        }
    }

    /**
     * A stream that delivers fewer than 4 bytes per fread() is still drained
     * up to the 4 bytes the integer needs.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFReadIntPartialReadStream(): void
    {
        $wrapperName = 'tcsinglebyteint';
        $registered = !\in_array($wrapperName, \stream_get_wrappers(), true);
        if ($registered) {
            \stream_wrapper_register($wrapperName, SingleByteStreamWrapper::class);
        }

        $file = $this->getTestObject();
        $handle = \fopen($wrapperName . '://data', 'rb');
        $this->assertNotFalse($handle);

        try {
            // Wrapper yields 'abcdefgh' one byte per read; first 4 bytes 'abcd'.
            $res = $file->fReadInt($handle);
            $expected = (\ord('a') << 24) | (\ord('b') << 16) | (\ord('c') << 8) | \ord('d');
            $this->assertSame($expected, $res);
        } finally {
            \fclose($handle);
            // Only unregister what this test registered, so a wrapper installed
            // by the surrounding environment is left in place.
            if ($registered) {
                \stream_wrapper_unregister($wrapperName);
            }
        }
    }

    /**
     * A stream that ends before 4 bytes are available must raise a FileException
     * rather than silently returning 0.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFReadIntTruncatedStreamThrows(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();

        $tmp = \tempnam(\sys_get_temp_dir(), 'tc');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, 'xy'); // only 2 bytes
        $handle = \fopen($tmp, 'rb');
        $this->assertNotFalse($handle);

        try {
            $file->fReadInt($handle);
        } finally {
            \fclose($handle);
            \unlink($tmp);
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfRead(): void
    {
        $file = $this->getTestObject();
        $handle = \fopen(\dirname(__DIR__) . '/src/File.php', 'rb');
        $this->assertNotFalse($handle);
        $res = $file->rfRead($handle, 2);
        $this->assertSame('<?', $res);
        $res = $file->rfRead($handle, 3);
        $this->assertSame('php', $res);
        \fclose($handle);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfReadException(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->rfRead(null, 2);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfReadClosedHandleException(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $handle = \fopen(__FILE__, 'rb');
        // ensure static analyzers know fopen succeeded
        $this->assertNotFalse($handle);

        \fclose($handle);
        // handle is still typed resource by analyzers even after close
        $file->rfRead($handle, 1);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfReadZeroLength(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $handle = \fopen(__FILE__, 'rb');
        $this->assertNotFalse($handle);

        try {
            // length 0: the while-loop condition (0 < 0) is immediately false,
            // so $data stays empty and FileException is thrown.
            $rfm = new \ReflectionMethod($file, 'rfRead');
            $rfm->invoke($file, $handle, 0);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfReadEofShorter(): void
    {
        $file = $this->getTestObject();
        $tmp = \tempnam(\sys_get_temp_dir(), 'tc');
        $this->assertNotFalse($tmp);
        \file_put_contents($tmp, 'xy');
        $handle = \fopen($tmp, 'rb');
        $this->assertNotFalse($handle);
        $res = $file->rfRead($handle, 10);
        $this->assertSame('xy', $res);
        \fclose($handle);
        \unlink($tmp);
    }

    /**
     * A wrapper whose stream_read() returns less than requested must still be
     * drained by rfRead() up to the requested length.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfReadRecursiveBufferedStream(): void
    {
        $registered = !\in_array('tcreadpartial', \stream_get_wrappers(), true);
        if ($registered) {
            \stream_wrapper_register('tcreadpartial', RecursiveReadStreamWrapper::class);
        }

        $file = $this->getTestObject();
        $handle = \fopen('tcreadpartial://buffered', 'rb');
        $this->assertNotFalse($handle);

        try {
            $res = $file->rfRead($handle, 5);
            $this->assertSame('abcde', $res);
        } finally {
            \fclose($handle);
            if ($registered) {
                \stream_wrapper_unregister('tcreadpartial');
            }
        }
    }

    /**
     * @param string       $file     File path
     * @param list<string> $expected Expected result
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[DataProvider('getAltFilePathsDataProvider')]
    public function testGetAltFilePaths(string $file, array $expected): void
    {
        $testObj = new \Com\Tecnick\File\File(['localhost']);
        $_SERVER['DOCUMENT_ROOT'] = '/var/www';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_URI'] = 'https://localhost/path/example.php';
        $alt = $testObj->getAltFilePaths($file);
        $this->assertSame($expected, $alt);
    }

    /**
     * Data provider for testGetAltFilePaths
     *
     * getAltFilePaths() drops duplicate candidates and returns a 0-indexed
     * list, so the expected values are plain sequential lists.
     *
     * @return array<array{string, list<string>}>
     */
    public static function getAltFilePathsDataProvider(): array
    {
        return [
            [
                'http://www.example.com/test.txt',
                [
                    'http://www.example.com/test.txt',
                ],
            ],
            [
                'https://localhost/path/test.txt',
                [
                    'https://localhost/path/test.txt',
                    '/var/www/path/test.txt',
                ],
            ],
            [
                '//www.example.com/space test.txt',
                [
                    '//www.example.com/space test.txt',
                    'https://www.example.com/space%20test.txt',
                ],
            ],
            [
                '/path/test.txt',
                [
                    '/path/test.txt',
                    '/var/www/path/test.txt',
                    'https://localhost/path/test.txt',
                ],
            ],
            [
                'https://localhost/path/test.php?a=0&b=1&amp;c=2;&amp;d="a+b%20c"',
                [
                    'https://localhost/path/test.php?a=0&b=1&amp;c=2;&amp;d="a+b%20c"',
                    'https://localhost/path/test.php?a=0&b=1&c=2;&d="a+b%20c"',
                ],
            ],
            [
                'path/test.txt',
                [
                    'path/test.txt',
                    'https://localhost/path/test.txt',
                ],
            ],
        ];
    }

    /**
     * Ensure getAltUrlFromPath returns the input when SCRIPT_URI cannot be parsed
     * (covers the guarded return on line 363 of File.php).
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetAltUrlFromPathUnparseableUri(): void
    {
        $testObj = $this->getTestObject();

        // set SCRIPT_URI to something parse_url will handle but without scheme/host
        $_SERVER['SCRIPT_URI'] = 'not-a-url';

        $rfm = new \ReflectionMethod($testObj, 'getAltUrlFromPath');
        $input = 'some/path.txt';
        $result = (string) $rfm->invoke($testObj, $input);
        $this->assertSame($input, $result, 'Expected original path when SCRIPT_URI lacks scheme/host');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetAltUrlFromPathSpoofedScriptUriRejected(): void
    {
        $testObj = $this->getTestObject();
        // No allowedHosts configured → deny SCRIPT_URI host.
        $_SERVER['SCRIPT_URI'] = 'https://attacker.internal/app/script.php';

        $rfm = new \ReflectionMethod($testObj, 'getAltUrlFromPath');
        $input = 'data/file.txt';
        $result = (string) $rfm->invoke($testObj, $input);
        $this->assertSame($input, $result, 'Spoofed SCRIPT_URI host must not be used to build a URL');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetAltUrlFromPathAllowlistedScriptUriAccepted(): void
    {
        $testObj = new \Com\Tecnick\File\File(['myapp.example.com']);
        $_SERVER['SCRIPT_URI'] = 'https://myapp.example.com/app/script.php';

        $rfm = new \ReflectionMethod($testObj, 'getAltUrlFromPath');
        $result = (string) $rfm->invoke($testObj, 'data/file.txt');
        $this->assertSame('https://myapp.example.com/data/file.txt', $result);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFileGetContentsMissingException(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fileGetContents('missing.txt');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFileGetContentsDoubleDotException(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fileGetContents(
            \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'something'
            . \DIRECTORY_SEPARATOR
            . '..'
            . \DIRECTORY_SEPARATOR
            . 'test.txt',
        );
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFileGetContentsForbiddenProtocolException(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fileGetContents('phar://test.txt');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFileGetContents(): void
    {
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);
        $res = $file->fileGetContents(__FILE__);
        $this->assertSame('<?php', \substr($res, 0, 5));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFileGetContentsCurl(): void
    {
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file = $this->getTestObject();
        $file->fileGetContents('http://www.example.com/test.txt');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testMaxRemoteSizeDefault(): void
    {
        $file = $this->getTestObject();
        $this->assertSame(52428800, $file->getMaxRemoteSize());
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testMaxRemoteSizeConfigurable(): void
    {
        $file = $this->getTestObject();
        $file->setMaxRemoteSize(1048576); // 1MB
        $this->assertSame(1048576, $file->getMaxRemoteSize());
    }

    /**
     * Every setter declares `: static`, so each must return the instance for
     * chaining.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllSettersAreFluent(): void
    {
        $file = $this->getTestObject();

        $this->assertSame($file, $file->setCurlOpts([CURLOPT_TIMEOUT => 5]));
        $this->assertSame($file, $file->setMaxRemoteSize(1024));
        $this->assertSame($file, $file->setCaseSensitivePaths(true));
        $this->assertSame($file, $file->setAllowedHosts(['example.com']));
        $this->assertSame($file, $file->setAllowedPaths(['*']));

        // Chained, the configuration must actually take effect.
        $configured = $this
            ->getTestObject()
            ->setMaxRemoteSize(2048)
            ->setAllowedHosts(['example.com'])
            ->setAllowedPaths(['*']);

        $this->assertSame(2048, $configured->getMaxRemoteSize());
        $this->assertTrue($configured->isAllowedUrl('https://example.com/a'));
        $this->assertTrue($configured->isAllowedFile(__FILE__));
    }

    /**
     * getFileData() tries the local path first and falls back to the remote
     * fetch.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetFileDataFallsBackFromLocalToRemote(): void
    {
        // A readable local file never reaches the remote branch, even with no
        // host allowlisted.
        $local = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);
        $data = $local->getFileData(__FILE__);
        $this->assertIsString($data);
        $this->assertStringStartsWith('<?php', $data);

        // A path that is not a valid local file falls through to getUrlData(),
        // which rejects the non-allowlisted host and returns false.
        $remote = $this->getTestObject();
        $this->assertFalse($remote->getFileData('https://example.com/missing.txt'));

        // Neither local nor remote: still false, no exception.
        $this->assertFalse($remote->getFileData('definitely-missing-' . \uniqid('', true) . '.txt'));
    }

    /**
     * getFileData() reaches the remote branch and returns the body when the
     * host is allowlisted.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetFileDataReadsRemoteBody(): void
    {
        $this->requireLocalHttpServer();

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $this->assertSame('', $file->getFileData('http://127.0.0.1:' . self::$serverPort . '/empty.php'));
    }

    /**
     * With the default CURLOPT_MAXREDIRS of 0 no redirect-validation callback
     * is installed, and cURL refuses to follow the redirect rather than
     * silently returning the redirect body.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataDoesNotFollowRedirectsByDefault(): void
    {
        $this->requireLocalHttpServer();

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $url = 'http://127.0.0.1:' . self::$serverPort . '/redirect.php?to=/empty.php';

        $ref = new \ReflectionClassConstant(\Com\Tecnick\File\File::class, 'CURLOPT_DEFAULT');
        /** @var array<int, mixed> $defaults */
        $defaults = $ref->getValue();
        $this->assertSame(0, $defaults[CURLOPT_MAXREDIRS] ?? null);

        $this->assertFalse($file->getUrlData($url));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSetAllowedHostsIsFluentAndUsedByValidator(): void
    {
        $file = new class() extends \Com\Tecnick\File\File {
            public function validateHostProxy(string $host): bool
            {
                return $this->isValidHost($host);
            }
        };

        $ret = $file->setAllowedHosts(['trusted.example']);
        $this->assertSame($file, $ret);

        $this->assertTrue($file->validateHostProxy('trusted.example'));
        $this->assertFalse($file->validateHostProxy('other.example'));
        $this->assertFalse($file->validateHostProxy(''));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSetAllowedPathsIsFluentAndUsedByValidator(): void
    {
        $file = new \Com\Tecnick\File\File();

        $tmpDir = \sys_get_temp_dir() . '/tc-lib-file-' . \uniqid('', true);
        $this->assertTrue(\mkdir($tmpDir, 0o755, true));

        $allowedPath = $tmpDir . '/file.txt';
        $this->assertSame(\file_put_contents($allowedPath, 'ok'), 2);

        try {
            $ret = $file->setAllowedPaths([$tmpDir]);
            $this->assertSame($file, $ret);

            $this->assertTrue($file->isValidFile($allowedPath));

            $blockedPath = \sys_get_temp_dir() . '/blocked-' . \uniqid('', true) . '.txt';
            $this->assertFalse($file->isValidFile($blockedPath));
        } finally {
            if (\is_file($allowedPath)) {
                \unlink($allowedPath);
            }

            if (\is_dir($tmpDir)) {
                \rmdir($tmpDir);
            }
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testResolveLocalPathUsesExplicitBaseDirs(): void
    {
        $file = new \Com\Tecnick\File\File();

        $baseDir = \sys_get_temp_dir() . '/tc-lib-file-' . \uniqid('', true);
        $imagesDir = $baseDir . '/images';
        $imagePath = $imagesDir . '/tcpdf_logo.jpg';

        $this->assertTrue(\mkdir($imagesDir, 0o755, true));
        $this->assertSame(2, \file_put_contents($imagePath, 'ok'));

        try {
            $resolved = $file->resolveLocalPath('images/tcpdf_logo.jpg', [$baseDir]);

            $this->assertSame(\realpath($imagePath), $resolved);
        } finally {
            if (\is_file($imagePath)) {
                \unlink($imagePath);
            }
            if (\is_dir($imagesDir)) {
                \rmdir($imagesDir);
            }
            if (\is_dir($baseDir)) {
                \rmdir($baseDir);
            }
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testResolveLocalPathLeavesSchemedInputsUntouched(): void
    {
        $file = new \Com\Tecnick\File\File();
        $url = 'https://example.com/logo.jpg';

        $this->assertSame($url, $file->resolveLocalPath($url, [__DIR__]));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testResolveLocalPathResolvesExistingPathWithoutBaseDirs(): void
    {
        $file = new \Com\Tecnick\File\File();

        // An existing path resolves directly via realpath(), before any base dir
        // is consulted.
        $this->assertSame(\realpath(__FILE__), $file->resolveLocalPath(__FILE__));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testResolveLocalPathSkipsEmptyAndInvalidBaseDirsThenFallsBack(): void
    {
        $file = new \Com\Tecnick\File\File();

        $missing = 'tc-missing-' . \uniqid('', true) . '-file.bin';
        $invalidBase = \sys_get_temp_dir() . '/tc-no-such-dir-' . \uniqid('', true);
        $this->assertFalse(\realpath($invalidBase));

        // '' is skipped, the non-existent base dir fails realpath() and is also
        // skipped, and with nothing left to try the original relative path is
        // returned unchanged.
        $this->assertSame($missing, $file->resolveLocalPath($missing, ['', $invalidBase]));
    }

    /**
     * @param string $path     Path to check
     * @param bool   $expected Whether the path holds a parent-directory segment
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[DataProvider('hasDoubleDotsDataProvider')]
    public function testHasDoubleDots(string $path, bool $expected): void
    {
        $file = new class() extends \Com\Tecnick\File\File {
            public function hasDoubleDotsProxy(string $path): bool
            {
                return $this->hasDoubleDots($path);
            }
        };

        $this->assertSame($expected, $file->hasDoubleDotsProxy($path), $path);
    }

    /**
     * Traversal vectors that must be rejected, and legitimate names that must not be.
     *
     * hasDoubleDots() decodes percent-encoded dots and separators plus HTML
     * entities before testing each path segment, so every encoding of a '..'
     * segment has to resolve to a rejection.
     *
     * @return array<string, array{string, bool}>
     */
    public static function hasDoubleDotsDataProvider(): array
    {
        return [
            'plain traversal' => ['/var/data/../test.txt', true],
            'leading traversal' => ['../test.txt', true],
            'trailing traversal' => ['/var/data/..', true],
            'backslash traversal' => ['\\var\\data\\..\\test.txt', true],
            'windows drive traversal' => ['C:..\\test.txt', true],
            'encoded dots lower' => ['/var/%2e%2e/test.txt', true],
            'encoded dots upper' => ['/var/%2E%2E/test.txt', true],
            'encoded separator' => ['..%2Ftest.txt', true],
            'encoded backslash separator' => ['..%5Ctest.txt', true],
            'fully encoded segment' => ['%2E%2E%2Ftest.txt', true],
            'html entity dots' => ['/var/&period;&period;/test.txt', true],
            'html entity separator' => ['..&sol;test.txt', true],
            // A double-encoded sequence names a literal directory ('%2e%2e'):
            // one decode short of '..', so it does not traverse. The alt-path
            // helpers decode once more, and isValidFile() re-checks the result.
            'double encoded' => ['/var/%252e%252e/test.txt', false],
            'plain path' => ['/var/data/test.txt', false],
            'dotfile' => ['/var/data/.hidden', false],
            'single dot segment' => ['/var/./data/test.txt', false],
            'dots inside filename' => ['/var/data/report..2024.txt', false],
            'dots inside filename encoded' => ['/var/data/report%2E%2E2024.txt', false],
            'four dots segment' => ['/var/data/..../test.txt', false],
            'windows drive path' => ['C:/var/data/test.txt', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Traversal vectors must be rejected through the public entry points too,
     * not only by the protected segment check.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testTraversalRejectedByPublicEntryPoints(): void
    {
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);

        foreach (['/var/data/../test.txt', '/var/%2e%2e/test.txt', '..%2Ftest.txt'] as $path) {
            $this->assertFalse($file->isAllowedFile($path), $path);
            $this->assertFalse($file->getLocalFileData($path), $path);
        }
    }

    /**
     * A filename that merely contains two consecutive dots is a valid name and
     * must be readable through the public entry points.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFilenameContainingDoubleDotsIsReadable(): void
    {
        $dir = \sys_get_temp_dir();
        $path = $dir . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true) . '..2024.txt';
        $this->assertNotFalse(\file_put_contents($path, 'payload'));

        try {
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$dir]);
            $this->assertTrue($file->isAllowedFile($path));
            $this->assertSame('payload', $file->fileGetContents($path));
        } finally {
            \unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // CURLOPT_REDIR_PROTOCOLS is present in CURLOPT_DEFAULT
    // -------------------------------------------------------------------------

    public function testCurlOptRedirProtocolsPresent(): void
    {
        $ref = new \ReflectionClassConstant(\Com\Tecnick\File\File::class, 'CURLOPT_DEFAULT');
        /** @var array<int, mixed> $opts */
        $opts = $ref->getValue();
        // The constant carries the integer pair; defaultCurlOptions()
        // replaces it with the string form where the build defines it.
        $this->assertArrayHasKey(CURLOPT_REDIR_PROTOCOLS, $opts);
        // Only HTTP and HTTPS are allowed for redirects.
        $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $opts[CURLOPT_REDIR_PROTOCOLS] ?? null);
        $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $opts[CURLOPT_PROTOCOLS] ?? null);
    }

    /**
     * The protocol restriction must survive the swap to the string options that
     * replace the deprecated CURLOPT_PROTOCOLS pair on libcurl 7.85+.
     */
    public function testDefaultCurlOptionsRestrictProtocols(): void
    {
        $rfm = new \ReflectionMethod(\Com\Tecnick\File\File::class, 'defaultCurlOptions');
        /** @var array<int, mixed> $opts */
        $opts = $rfm->invoke(null);

        if (\defined('CURLOPT_PROTOCOLS_STR') && \defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $this->assertSame('http,https', $opts[CURLOPT_PROTOCOLS_STR] ?? null);
            $this->assertSame('http,https', $opts[CURLOPT_REDIR_PROTOCOLS_STR] ?? null);
            // The deprecated pair must not be sent alongside the replacement.
            $this->assertArrayNotHasKey(CURLOPT_PROTOCOLS, $opts);
            $this->assertArrayNotHasKey(CURLOPT_REDIR_PROTOCOLS, $opts);
            return;
        }

        $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $opts[CURLOPT_PROTOCOLS] ?? null);
        $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $opts[CURLOPT_REDIR_PROTOCOLS] ?? null);
    }

    /**
     * Hostnames are case-insensitive (RFC 4343), so allowlist matching must not
     * depend on the case or a trailing root dot of either operand.
     *
     * @param string $allowed  Allowlist entry
     * @param string $url      URL to validate
     * @param bool   $expected Expected validation result
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[DataProvider('hostCaseDataProvider')]
    public function testHostAllowlistMatchingIsCaseInsensitive(string $allowed, string $url, bool $expected): void
    {
        $file = new \Com\Tecnick\File\File([$allowed]);
        $this->assertSame($expected, $file->isAllowedUrl($url), $url);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function hostCaseDataProvider(): array
    {
        return [
            'exact' => ['example.com', 'https://example.com/a', true],
            'uppercase url' => ['example.com', 'https://EXAMPLE.COM/a', true],
            'mixed case url' => ['example.com', 'https://Example.Com/a', true],
            'uppercase allowlist' => ['EXAMPLE.COM', 'https://example.com/a', true],
            'mixed case both' => ['ExAmPlE.cOm', 'https://eXaMpLe.CoM/a', true],
            'trailing root dot url' => ['example.com', 'https://example.com./a', true],
            'trailing root dot allowlist' => ['example.com.', 'https://example.com/a', true],
            'padded allowlist entry' => ['  example.com  ', 'https://example.com/a', true],
            'different host' => ['example.com', 'https://evil.com/a', false],
            'suffix is not a match' => ['example.com', 'https://notexample.com/a', false],
            'subdomain is not a match' => ['example.com', 'https://sub.example.com/a', false],
            // parse_url() reports the port separately, so a URL matches on host
            // alone: the allowlist constrains the host, not the port. The port
            // is part of an HTTP_HOST value and is covered separately below.
            'url port is not part of the host' => ['example.com', 'https://example.com:8080/a', true],
        ];
    }

    /**
     * $_SERVER['HTTP_HOST'] carries the port when it is non-default and is
     * matched as a whole, so an allowlist entry has to include the port to
     * enable the HTTP_HOST-driven alt-path helpers on that origin.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testHttpHostAllowlistIncludesThePort(): void
    {
        $withoutPort = new \Com\Tecnick\File\File(['example.com']);
        $rfm = new \ReflectionMethod($withoutPort, 'isValidHost');
        $this->assertFalse($rfm->invoke($withoutPort, 'example.com:8080'));
        $this->assertTrue($rfm->invoke($withoutPort, 'example.com'));

        $withPort = new \Com\Tecnick\File\File(['example.com:8080']);
        $rfm = new \ReflectionMethod($withPort, 'isValidHost');
        $this->assertTrue($rfm->invoke($withPort, 'example.com:8080'));
        $this->assertTrue($rfm->invoke($withPort, 'EXAMPLE.COM:8080'));
        $this->assertFalse($rfm->invoke($withPort, 'example.com'));
    }

    /**
     * A SCRIPT_URI on a non-default port must keep that port in the candidate
     * URL: dropping it would name the default port of the same host, which is
     * a different origin.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetAltUrlFromPathPreservesTheScriptUriPort(): void
    {
        $_SERVER['SCRIPT_URI'] = 'https://myapp.example.com:8443/app/script.php';

        $file = new \Com\Tecnick\File\File(['myapp.example.com']);
        $rfm = new \ReflectionMethod($file, 'getAltUrlFromPath');

        $this->assertSame('https://myapp.example.com:8443/data/file.txt', $rfm->invoke($file, 'data/file.txt'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSetAllowedHostsNormalizesEntries(): void
    {
        $file = $this->getTestObject();
        $file->setAllowedHosts(['CDN.Example.COM', 'cdn.example.com.', '']);

        $this->assertTrue($file->isAllowedUrl('https://cdn.example.com/img.png'));
        $this->assertFalse($file->isAllowedUrl('https://other.example.com/img.png'));
    }

    /**
     * A public by-value validator must accept a literal: the by-reference
     * isValidURL()/isValidFile() pair raises a fatal error for any argument
     * that is not a variable.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testByValueValidatorsAcceptLiterals(): void
    {
        $file = new \Com\Tecnick\File\File(['example.com'], 52_428_800, [], null, null, ['*']);

        $this->assertTrue($file->isAllowedUrl('https://example.com/a'));
        $this->assertFalse($file->isAllowedUrl('https://evil.com/a'));
        $this->assertTrue($file->isAllowedFile(__FILE__));
        $this->assertFalse($file->isAllowedFile('../escape.txt'));
    }

    /**
     * The by-value wrappers must leave the caller's variable untouched, unlike
     * the by-reference methods they delegate to.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testByValueValidatorsDoNotMutateTheArgument(): void
    {
        $file = new \Com\Tecnick\File\File(['example.com'], 52_428_800, [], null, null, ['*']);

        $url = '  https://example.com/a  ';
        $this->assertTrue($file->isAllowedUrl($url));
        $this->assertSame('  https://example.com/a  ', $url);

        $path = __FILE__;
        $this->assertTrue($file->isAllowedFile($path));
        $this->assertSame(__FILE__, $path);

        // The by-reference counterparts do rewrite their argument.
        $refUrl = '  https://example.com/a  ';
        $this->assertTrue($file->isValidURL($refUrl));
        $this->assertSame('https://example.com/a', $refUrl);

        $refPath = __FILE__;
        $this->assertTrue($file->isValidFile($refPath));
        $this->assertSame('file://' . __FILE__, $refPath);
    }

    /**
     * An allowlist root that traverses a symlink must still match files inside
     * it: isValidFile() compares the realpath() of the candidate, so the roots
     * have to be canonicalized too.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSymlinkedAllowedRootMatchesFilesInside(): void
    {
        $base = self::makeTempDir();
        $real = $base . \DIRECTORY_SEPARATOR . 'real';
        $link = $base . \DIRECTORY_SEPARATOR . 'link';

        $this->assertTrue(\mkdir($real, 0o777, true));
        if (!self::trySymlink($real, $link)) {
            $this->markTestSkipped('symlink() not permitted in this environment');
        }

        $target = $real . \DIRECTORY_SEPARATOR . 'data.txt';
        $this->assertNotFalse(\file_put_contents($target, 'payload'));

        try {
            // Root given as the symlink, file addressed through the symlink.
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$link]);
            $viaLink = $link . \DIRECTORY_SEPARATOR . 'data.txt';
            $this->assertTrue($file->isAllowedFile($viaLink));
            $this->assertSame('payload', $file->fileGetContents($viaLink));

            // The same file addressed through its canonical path.
            $this->assertTrue($file->isAllowedFile($target));

            // A file outside the root is still rejected.
            $outside = $base . \DIRECTORY_SEPARATOR . 'outside.txt';
            $this->assertNotFalse(\file_put_contents($outside, 'nope'));
            $this->assertFalse($file->isAllowedFile($outside));
            \unlink($outside);
        } finally {
            \unlink($target);
            self::removeSymlink($link);
            \rmdir($real);
            \rmdir($base);
        }
    }

    /**
     * A symlink inside a trusted root that points outside it must be rejected:
     * canonicalization is what enforces the boundary.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSymlinkEscapingAllowedRootIsRejected(): void
    {
        $base = self::makeTempDir();
        $root = $base . \DIRECTORY_SEPARATOR . 'root';

        $this->assertTrue(\mkdir($root, 0o777, true));
        $secret = $base . \DIRECTORY_SEPARATOR . 'secret.txt';
        $this->assertNotFalse(\file_put_contents($secret, 'secret'));

        $escape = $root . \DIRECTORY_SEPARATOR . 'escape.txt';
        if (!self::trySymlink($secret, $escape)) {
            \unlink($secret);
            \rmdir($root);
            \rmdir($base);
            $this->markTestSkipped('symlink() not permitted in this environment');
        }

        try {
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$root]);
            $this->assertFalse($file->isAllowedFile($escape));
            $this->assertFalse($file->getLocalFileData($escape));
        } finally {
            self::removeSymlink($escape);
            \unlink($secret);
            \rmdir($root);
            \rmdir($base);
        }
    }

    // -------------------------------------------------------------------------
    // TLS verification flags pinned in fixed options
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
        $this->assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST] ?? null);
        $this->assertTrue(($opts[CURLOPT_SSL_VERIFYPEER] ?? null) === true);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSslVerificationCannotBeOverriddenByCustomOptions(): void
    {
        $testObj = $this->getTestObject();
        // Set custom curl options that try to disable SSL verification
        $testObj->setCurlOpts([
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => false,
        ]);

        // Assert on the merged result that getUrlData() actually hands to
        // curl_setopt_array(): reading the untouched fixedCurlOpts property
        // would pass even if the merge order were reversed.
        $rfm = new \ReflectionMethod($testObj, 'mergeCurlOptions');
        /** @var array<int, mixed> $merged */
        $merged = $rfm->invoke($testObj, []);

        $this->assertSame(2, $merged[CURLOPT_SSL_VERIFYHOST] ?? null);
        $this->assertTrue($merged[CURLOPT_SSL_VERIFYPEER] ?? null);
        $this->assertTrue($merged[CURLOPT_RETURNTRANSFER] ?? null);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testCustomCurlOptionsOverrideDefaultsButNotFixed(): void
    {
        $testObj = $this->getTestObject();
        $testObj->setCurlOpts([
            CURLOPT_TIMEOUT => 99,
            CURLOPT_FAILONERROR => false,
        ]);

        $rfm = new \ReflectionMethod($testObj, 'mergeCurlOptions');
        /** @var array<int, mixed> $merged */
        $merged = $rfm->invoke($testObj, [CURLOPT_FOLLOWLOCATION => true]);

        // A per-request option survives when no later layer sets it.
        $this->assertTrue($merged[CURLOPT_FOLLOWLOCATION] ?? null);
        // Custom options win over the defaults.
        $this->assertSame(99, $merged[CURLOPT_TIMEOUT] ?? null);
        // Fixed options win over the custom ones.
        $this->assertTrue($merged[CURLOPT_FAILONERROR] ?? null);
    }

    // -------------------------------------------------------------------------
    // HTTP_HOST allowlisting, guarding against SSRF
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSpoofedHttpHostIsRejectedByDefault(): void
    {
        $testObj = $this->getTestObject();
        // No allowedHosts configured → every host is denied.
        $_SERVER['HTTP_HOST'] = 'evil.internal';
        $_SERVER['HTTPS'] = 'on';

        $rfm = new \ReflectionMethod($testObj, 'getAltMissingUrlProtocol');
        $input = '//evil.internal/steal';
        $result = (string) $rfm->invoke($testObj, $input);
        // Without a trusted host the path must come back unchanged (decoded only).
        $this->assertSame(
            \htmlspecialchars_decode($input),
            $result,
            'Spoofed HTTP_HOST must not be used to build a URL',
        );
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllowlistedHttpHostIsAccepted(): void
    {
        $testObj = new \Com\Tecnick\File\File(['myapp.example.com']);
        $_SERVER['HTTP_HOST'] = 'myapp.example.com';
        $_SERVER['HTTPS'] = 'on';

        $rfm = new \ReflectionMethod($testObj, 'getAltMissingUrlProtocol');
        $result = (string) $rfm->invoke($testObj, '//myapp.example.com/path/file.txt');
        $this->assertSame('https://myapp.example.com/path/file.txt', $result);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetAltPathFromUrlSpoofedHostRejected(): void
    {
        $testObj = $this->getTestObject();
        // No allowedHosts → deny.
        $_SERVER['HTTP_HOST'] = 'attacker.internal';
        $_SERVER['DOCUMENT_ROOT'] = '/var/www';
        $_SERVER['HTTPS'] = 'off';

        $rfm = new \ReflectionMethod($testObj, 'getAltPathFromUrl');
        $url = 'http://attacker.internal/secret';
        $result = (string) $rfm->invoke($testObj, $url);
        $this->assertSame($url, $result, 'Spoofed host must not be used to build a local path');
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathAcceptsAllowedPrefix(): void
    {
        $baseDir = \sys_get_temp_dir() . '/tc-lib-file-' . \uniqid('', true);
        $this->assertTrue(\mkdir($baseDir, 0o755, true));

        $testObj = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$baseDir]);

        try {
            $path = $baseDir . '/assets/file.txt';
            $this->assertTrue($testObj->isValidFile($path));
        } finally {
            if (\is_dir($baseDir . '/assets')) {
                \rmdir($baseDir . '/assets');
            }
            if (\is_dir($baseDir)) {
                \rmdir($baseDir);
            }
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathRejectsNonMatchingPrefix(): void
    {
        $baseDir = \sys_get_temp_dir() . '/tc-lib-file-' . \uniqid('', true);
        $this->assertTrue(\mkdir($baseDir, 0o755, true));

        $testObj = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$baseDir]);

        try {
            $path = \sys_get_temp_dir() . '/tc-lib-file-' . \uniqid('', true) . '/file.txt';
            $this->assertFalse($testObj->isValidFile($path));
        } finally {
            if (\is_dir($baseDir)) {
                \rmdir($baseDir);
            }
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathRejectsSiblingPrefixBypass(): void
    {
        $baseDir = \sys_get_temp_dir() . '/tc-lib-file-' . \uniqid('', true);
        $this->assertTrue(\mkdir($baseDir, 0o755, true));

        $testObj = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$baseDir]);

        try {
            $path = $baseDir . '_evil/secret.txt';
            $this->assertFalse($testObj->isValidFile($path));
        } finally {
            if (\is_dir($baseDir)) {
                \rmdir($baseDir);
            }
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testIsPathWithinAllowedRootsSkipsEmptyRoots(): void
    {
        $testObj = new class() extends \Com\Tecnick\File\File {
            /**
             * @param array<int, string> $roots
             */
            public function isPathWithinAllowedRootsProxy(string $path, array $roots): bool
            {
                return $this->isPathWithinAllowedRoots($path, $roots);
            }
        };

        // Each root is asserted on its own: in a list that also contains '/'
        // or '/var/www' the result holds whether or not '' is skipped.
        $this->assertFalse($testObj->isPathWithinAllowedRootsProxy('/var/www/app/file.txt', ['']));

        // A root that rtrim()s to '' is skipped for the same reason: it would
        // otherwise turn into a prefix that matches every absolute path.
        $this->assertFalse($testObj->isPathWithinAllowedRootsProxy('/var/www/app/file.txt', ['/']));
        $this->assertFalse($testObj->isPathWithinAllowedRootsProxy('/var/www/app/file.txt', ['///']));

        $this->assertTrue($testObj->isPathWithinAllowedRootsProxy('/var/www/app/file.txt', ['/var/www']));
        $this->assertTrue($testObj->isPathWithinAllowedRootsProxy('/var/www/app/file.txt', ['', '/', '/var/www']));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllowedPathsAreNormalizedInConstructorForWindowsPaths(): void
    {
        $testObj = new class([], 52_428_800, [], null, null, [' C:\\Trusted\\Base\\ ', 'D:', '']) extends
            \Com\Tecnick\File\File {
            public function isPathAllowedProxy(string $path): bool
            {
                return $this->isPathWithinAllowedRoots($path, $this->allowedPaths);
            }

            /**
             * @return array<int, string>
             */
            public function getAllowedPathsProxy(): array
            {
                return \array_values($this->allowedPaths);
            }
        };

        $this->assertSame(['c:/Trusted/Base', 'd:'], $testObj->getAllowedPathsProxy());
        $this->assertTrue($testObj->isPathAllowedProxy('c:/Trusted/Base/file.txt'));
        $this->assertTrue($testObj->isPathAllowedProxy('d:/folder/file.txt'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllowedPathsAreNormalizedInSetterForWindowsPaths(): void
    {
        $testObj = new class() extends \Com\Tecnick\File\File {
            public function isPathAllowedProxy(string $path): bool
            {
                return $this->isPathWithinAllowedRoots($path, $this->allowedPaths);
            }

            /**
             * @return array<int, string>
             */
            public function getAllowedPathsProxy(): array
            {
                return \array_values($this->allowedPaths);
            }
        };

        $testObj->setAllowedPaths([' E:\\Share\\Root\\ ', 'E:\\Share\\Root\\']);

        $this->assertSame(['e:/Share/Root'], $testObj->getAllowedPathsProxy());
        $this->assertTrue($testObj->isPathAllowedProxy('e:/Share/Root/file.txt'));
        $this->assertFalse($testObj->isPathAllowedProxy('e:/Share/Root_evil/file.txt'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathReturnsFalseWhenNearestParentCannotBeResolved(): void
    {
        $testObj = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['foo']);

        $file = 'foo';
        $this->assertFalse($testObj->isValidFile($file));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathRejectsSymlinkEscape(): void
    {
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink is not available in this environment');
        }

        $root = \sys_get_temp_dir() . '/tcfile_' . \uniqid('', true);
        $base = $root . '/base';
        $allowedDir = $base . '/allowed';
        $outsideDir = $root . '/outside';
        \mkdir($allowedDir, 0o777, true);
        \mkdir($outsideDir, 0o777, true);

        // The escape target is a real file inside the test's own tree, so
        // that realpath() resolves it on every platform.
        $outsideFile = $outsideDir . '/secret.txt';
        \file_put_contents($outsideFile, 'outside');

        $insideFile = $allowedDir . '/inside.txt';
        \file_put_contents($insideFile, 'inside');

        $link = $allowedDir . '/escape-link';

        if (!self::withoutWarnings(static fn(): bool => \symlink($outsideFile, $link))) {
            $this->markTestSkipped('unable to create symlink in this environment');
        }

        $testObj = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$base]);

        try {
            // Positive control: without it, a validator that rejected everything
            // would pass this test.
            $inside = $insideFile;
            $this->assertTrue($testObj->isValidFile($inside));

            $this->assertFalse($testObj->isValidFile($link));
        } finally {
            self::removeTree([$link, $insideFile, $outsideFile], [$allowedDir, $outsideDir, $base, $root]);
        }
    }

    /**
     * Remove the given files or symlinks, then the given directories.
     *
     * @param array<string> $paths Files or symlinks to unlink.
     * @param array<string> $dirs  Directories to remove, innermost first.
     */
    private static function removeTree(array $paths, array $dirs): void
    {
        foreach ($paths as $path) {
            if (!\is_link($path) && !\file_exists($path)) {
                continue;
            }

            self::withoutWarnings(static fn(): bool => \unlink($path));
        }

        foreach ($dirs as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }

            self::withoutWarnings(static fn(): bool => \rmdir($dir));
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathRejectsSymlinkDirectoryEscapeForMissingTarget(): void
    {
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink is not available in this environment');
        }

        $base = \sys_get_temp_dir() . '/tcfile_' . \uniqid('', true);
        $allowedDir = $base . '/allowed';
        $outsideDir = \sys_get_temp_dir() . '/tcfile_outside_' . \uniqid('', true);
        \mkdir($allowedDir, 0o777, true);
        \mkdir($outsideDir, 0o777, true);

        $link = $allowedDir . '/escape-link';
        if (!self::withoutWarnings(static fn(): bool => \symlink($outsideDir, $link))) {
            $this->markTestSkipped('unable to create symlink in this environment');
        }

        $testObj = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$base]);
        $target = $link . '/new-file.txt';

        try {
            // Positive control: a missing target that resolves to a parent
            // inside the allowed root is accepted, so the assertion below
            // distinguishes the symlink escape from a blanket rejection.
            $inside = $allowedDir . '/new-file.txt';
            $this->assertTrue($testObj->isValidFile($inside));

            $this->assertFalse($testObj->isValidFile($target));
        } finally {
            if (\is_link($link) || \file_exists($link)) {
                self::withoutWarnings(static fn(): bool => \unlink($link));
            }

            if (\is_dir($outsideDir)) {
                self::withoutWarnings(static fn(): bool => \rmdir($outsideDir));
            }

            if (\is_dir($allowedDir)) {
                self::withoutWarnings(static fn(): bool => \rmdir($allowedDir));
            }

            if (\is_dir($base)) {
                self::withoutWarnings(static fn(): bool => \rmdir($base));
            }
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testIsValidUrlReturnsFalseWhenParseFails(): void
    {
        $testObj = new \Com\Tecnick\File\File(['localhost']);
        $url = 'http://:\\';

        $this->assertFalse($testObj->isValidURL($url));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testIsValidUrlReturnsFalseWhenHostMissing(): void
    {
        $testObj = new \Com\Tecnick\File\File(['localhost']);
        $url = 'http:/path/without/host';

        $this->assertFalse($testObj->isValidURL($url));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testIsValidUrlReturnsFalseWhenTrimmedUrlIsEmpty(): void
    {
        $testObj = new \Com\Tecnick\File\File(['localhost']);
        $url = " \t\n\r ";

        $this->assertFalse($testObj->isValidURL($url));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathRejectsNonFileScheme(): void
    {
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);

        $ftpPath = 'ftp://example.com/file.txt';
        $this->assertFalse($file->isValidFile($ftpPath));

        $pharPath = 'phar://archive/file.txt';
        $this->assertFalse($file->isValidFile($pharPath));

        $httpPath = 'http://www.example.com/file.txt';
        $this->assertFalse($file->isValidFile($httpPath));

        $localPath = './some/file.txt';
        $this->assertTrue($file->isValidFile($localPath));

        $fileUrl = 'file://some/file.txt';
        $this->assertTrue($file->isValidFile($fileUrl));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathRejectsEmptyFileUrlPathEvenWithWildcardTrust(): void
    {
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);

        $emptyFileUrl = 'file://   ';
        $this->assertFalse($file->isValidFile($emptyFileUrl));
    }

    // -------------------------------------------------------------------------
    // Iterative rfRead: single-byte chunk delivery
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfReadSingleByteChunks(): void
    {
        $wrapperName = 'tcsinglebyte';
        $registered = !\in_array($wrapperName, \stream_get_wrappers(), true);
        if ($registered) {
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
            if ($registered) {
                \stream_wrapper_unregister($wrapperName);
            }
        }
    }

    // -------------------------------------------------------------------------
    // rfRead inner break: fread returns '' before feof signals end-of-stream
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRfReadBreakOnEmptyChunk(): void
    {
        $wrapperName = 'tcemptyread';
        $registered = !\in_array($wrapperName, \stream_get_wrappers(), true);
        if ($registered) {
            \stream_wrapper_register($wrapperName, EmptyReadStreamWrapper::class);
        }

        $file = $this->getTestObject();
        $handle = \fopen($wrapperName . '://data', 'rb');
        $this->assertNotFalse($handle);

        try {
            // The wrapper returns 'ab' on the first read then '' forever
            // while stream_eof() never returns true, so rfRead() hits the
            // inner break after the second fread() call.
            $res = $file->rfRead($handle, 10);
            $this->assertSame('ab', $res);
        } finally {
            \fclose($handle);
            if ($registered) {
                \stream_wrapper_unregister($wrapperName);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Size-limit callback direct-invocation tests (cURL size-limit logic)
    // -------------------------------------------------------------------------

    /**
     * Build the declared-size progress callback for a given limit.
     *
     * @param bool $declaredOversize Flag the callback raises, by reference.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    private function declaredSizeCallback(int $maxRemoteSize, bool &$declaredOversize): callable
    {
        $file = $this->getTestObject();
        $file->setMaxRemoteSize($maxRemoteSize);

        $rfm = new \ReflectionMethod($file, 'createDeclaredSizeCallback');
        $args = [&$declaredOversize];

        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);
        $this->assertIsCallable($callback);

        return $callback;
    }

    /**
     * Build the size-limited write callback for a given limit.
     *
     * @param string $body      Accumulated body, by reference.
     * @param int    $bytesRead Bytes buffered, by reference.
     * @param bool   $oversize  Flag the callback raises, by reference.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    private function writeCallback(int $maxRemoteSize, string &$body, int &$bytesRead, bool &$oversize): callable
    {
        $file = $this->getTestObject();
        $file->setMaxRemoteSize($maxRemoteSize);

        $rfm = new \ReflectionMethod($file, 'createWriteCallback');
        $args = [&$body, &$bytesRead, &$oversize];

        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);
        $this->assertIsCallable($callback);

        return $callback;
    }

    /**
     * A declared size within the limit lets the transfer proceed.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeclaredSizeCallbackAcceptsASizeWithinTheLimit(): void
    {
        $declaredOversize = false;
        $callback = $this->declaredSizeCallback(100, $declaredOversize);

        $this->assertSame(0, (int) $callback(null, 50, 0, 0, 0));
        $this->assertFalse($declaredOversize);
    }

    /**
     * A declared size over the limit aborts before any byte is downloaded.
     *
     * The downloaded-bytes argument is 0, the state the callback is in once the
     * response headers have been read and nothing else.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeclaredSizeCallbackAbortsBeforeAnyByteIsRead(): void
    {
        $declaredOversize = false;
        $callback = $this->declaredSizeCallback(100, $declaredOversize);

        $this->assertSame(1, (int) $callback(null, 200, 0, 0, 0));
        $this->assertTrue($declaredOversize);
    }

    /**
     * libcurl reports a size of 0 until it knows one, and never for a chunked
     * response. That must not be read as "declared over the limit".
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDeclaredSizeCallbackIgnoresAnUnknownSize(): void
    {
        $declaredOversize = false;
        $callback = $this->declaredSizeCallback(100, $declaredOversize);

        $this->assertSame(0, (int) $callback(null, 0, 500, 0, 0));
        $this->assertFalse($declaredOversize);
    }

    /**
     * The write callback accumulates the body and reports every byte written.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWriteCallbackBuffersChunksBelowTheLimit(): void
    {
        $body = '';
        $bytesRead = 0;
        $oversize = false;
        $callback = $this->writeCallback(100, $body, $bytesRead, $oversize);

        $this->assertSame(4, $callback(null, 'abcd'));
        $this->assertSame(3, $callback(null, 'efg'));
        $this->assertSame('abcdefg', $body);
        $this->assertSame(7, $bytesRead);
        $this->assertFalse($oversize);
    }

    /**
     * A response of exactly the configured size is accepted: the limit is the
     * largest response a caller expects, not the first one they refuse.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWriteCallbackAcceptsExactlyTheLimit(): void
    {
        $body = '';
        $bytesRead = 0;
        $oversize = false;
        $callback = $this->writeCallback(10, $body, $bytesRead, $oversize);

        $this->assertSame(10, $callback(null, \str_repeat('x', 10)));
        $this->assertSame(10, $bytesRead);
        $this->assertSame(\str_repeat('x', 10), $body);
        $this->assertFalse($oversize, 'a response of exactly the limit is not oversize');
    }

    /**
     * The first byte past the limit aborts the transfer, and the chunk carrying
     * it is not buffered, so the accumulated body never exceeds the limit.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWriteCallbackAbortsOnTheFirstByteBeyondTheLimit(): void
    {
        $body = '';
        $bytesRead = 0;
        $oversize = false;
        $callback = $this->writeCallback(10, $body, $bytesRead, $oversize);

        $this->assertSame(10, $callback(null, \str_repeat('x', 10)));
        $this->assertSame(0, $callback(null, 'y'));

        // The rejected chunk never reaches the buffer.
        $this->assertSame(\str_repeat('x', 10), $body);
        $this->assertSame(10, $bytesRead);
        $this->assertTrue($oversize, 'the byte beyond the limit must raise the flag');
    }

    /**
     * A single chunk crossing the limit is rejected whole, so the buffer never
     * grows past the limit even by part of a chunk.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWriteCallbackRejectsAnOversizeChunkWithoutBufferingIt(): void
    {
        $body = '';
        $bytesRead = 0;
        $oversize = false;
        $callback = $this->writeCallback(10, $body, $bytesRead, $oversize);

        $this->assertSame(0, $callback(null, \str_repeat('x', 11)));
        $this->assertTrue($oversize);
        $this->assertSame('', $body);
        $this->assertSame(0, $bytesRead);
    }

    /**
     * An empty chunk is not the abort signal: 0 bytes written out of 0 received
     * is a complete write.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWriteCallbackTreatsAnEmptyChunkAsSuccess(): void
    {
        $body = '';
        $bytesRead = 0;
        $oversize = false;
        $callback = $this->writeCallback(10, $body, $bytesRead, $oversize);

        $this->assertSame(0, $callback(null, ''));
        $this->assertFalse($oversize);
        $this->assertSame(0, $bytesRead);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testBuildRedirectUrlCoversUnsupportedAndRelativeForms(): void
    {
        $file = $this->getTestObject();

        $buildRedirectUrl = \Closure::bind(
            static fn(
                \Com\Tecnick\File\File $obj,
                string $location,
                string $baseUrl,
            ): string|false => $obj->buildRedirectUrl($location, $baseUrl),
            null,
            \Com\Tecnick\File\File::class,
        );

        $this->assertFalse($buildRedirectUrl($file, '   ', 'https://example.test/base'));
        $this->assertFalse($buildRedirectUrl($file, '/next', 'http://:\\'));
        $this->assertFalse($buildRedirectUrl($file, '/next', 'ftp://example.test/base'));
        $this->assertSame('https://cdn.example.test/file.txt', $buildRedirectUrl(
            $file,
            '//cdn.example.test/file.txt',
            'https://example.test/base',
        ));
        $this->assertSame('https://example.test/path/next.txt', $buildRedirectUrl(
            $file,
            'next.txt',
            'https://example.test/path/current.php',
        ));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRedirectValidationCallbackRejectsEmptyAndNonCurlLocationHeaders(): void
    {
        $file = new \Com\Tecnick\File\File(['allowed.example']);

        $rfm = new \ReflectionMethod($file, 'createRedirectValidationCallback');

        // An empty Location value: aborts the transfer and flags the redirect.
        $invalidRedirect = false;
        $args = [&$invalidRedirect, 'https://allowed.example/start'];
        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);
        $this->assertSame(0, $callback(null, "Location:   \r\n"));
        // The flag, not the return value, is what getUrlData() consumes.
        $this->assertTrue($invalidRedirect);

        // A non-CurlHandle first argument: the effective URL cannot be read, so
        // the target cannot be validated and the transfer is aborted.
        $invalidRedirect = false;
        $args = [&$invalidRedirect, 'https://allowed.example/start'];
        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);
        $this->assertSame(0, $callback(null, "Location: /next\r\n"));
        $this->assertTrue($invalidRedirect);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRedirectValidationCallbackPassesThroughNonLocationHeaders(): void
    {
        $file = new \Com\Tecnick\File\File(['allowed.example']);

        $rfm = new \ReflectionMethod($file, 'createRedirectValidationCallback');

        $invalidRedirect = false;
        $args = [&$invalidRedirect, 'https://allowed.example/start'];
        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);

        $header = "Content-Type: text/plain\r\n";
        $this->assertSame(\strlen($header), $callback(null, $header));
        $this->assertFalse($invalidRedirect);
    }

    // -------------------------------------------------------------------------
    // Local HTTP server tests: size-limit enforcement and return value
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataWithValidRedirectWhenMaxRedirsEnabled(): void
    {
        $this->requireLocalHttpServer();

        if ((string) \ini_get('open_basedir') !== '') {
            $this->markTestSkipped('Redirect-follow tests require open_basedir to be disabled');
        }

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $file->setCurlOpts([CURLOPT_MAXREDIRS => 3]);

        $result = $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/redirect.php?to=/empty.php');
        $this->assertSame('', $result);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataReturnsFalseOnInvalidRedirectWhenMaxRedirsEnabled(): void
    {
        $this->requireLocalHttpServer();

        if ((string) \ini_get('open_basedir') !== '') {
            $this->markTestSkipped('Redirect-follow tests require open_basedir to be disabled');
        }

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $file->setCurlOpts([CURLOPT_MAXREDIRS => 3]);

        // The redirect target resolves and answers, so that only the
        // allowlist decides the outcome. 'localhost' is the same server as
        // '127.0.0.1' but is not the same allowlist entry, so the callback
        // must reject it.
        $result = $file->getUrlData(
            'http://127.0.0.1:' . self::$serverPort . '/redirect.php?to='
                . \rawurlencode('http://localhost:' . self::$serverPort . '/empty.php'),
        );
        $this->assertFalse($result);
    }

    /**
     * The control for the test above: the same redirect to an allowlisted
     * target is followed and returns the body.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataFollowsAnAllowlistedAbsoluteRedirect(): void
    {
        $this->requireLocalHttpServer();

        if ((string) \ini_get('open_basedir') !== '') {
            $this->markTestSkipped('Redirect-follow tests require open_basedir to be disabled');
        }

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $file->setCurlOpts([CURLOPT_MAXREDIRS => 3]);

        $result = $file->getUrlData(
            'http://127.0.0.1:' . self::$serverPort . '/redirect.php?to='
                . \rawurlencode('http://127.0.0.1:' . self::$serverPort . '/empty.php'),
        );
        $this->assertSame('', $result);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataSizeExceeded(): void
    {
        $this->requireLocalHttpServer();

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        // large.php declares its length, so the 1 000-byte response is refused
        // on the declared size, before any of the body is buffered.
        $file->setMaxRemoteSize(10);

        try {
            $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/large.php');
            $this->fail('getUrlData() returned an oversize response');
        } catch (\Com\Tecnick\File\Exception $exception) {
            $this->assertStringContainsString('exceeds maximum allowed size of 10 bytes', $exception->getMessage());
            $this->assertStringContainsString('rejected before reading the body', $exception->getMessage());
        }
    }

    /**
     * A response of exactly the configured size is content, not an error: the
     * limit names the largest response the caller expects.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataAcceptsAResponseOfExactlyTheLimit(): void
    {
        $this->requireLocalHttpServer();

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $url = 'http://127.0.0.1:' . self::$serverPort . '/large.php';

        $this->assertSame(1000, \strlen((string) $file->setMaxRemoteSize(1000)->getUrlData($url)));

        // One byte short of the body, and the same response is refused.
        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file->setMaxRemoteSize(999)->getUrlData($url);
    }

    /**
     * A response that declares no length is bounded while it streams: the
     * declared-size guard cannot see it, so the write callback is what stops it.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataBoundsAResponseOfUndeclaredLength(): void
    {
        $this->requireLocalHttpServer();

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $url = 'http://127.0.0.1:' . self::$serverPort . '/chunked.php';

        // The whole 1 000-byte body is readable when the limit allows it.
        $this->assertSame(1000, \strlen((string) $file->setMaxRemoteSize(1000)->getUrlData($url)));

        try {
            $file->setMaxRemoteSize(500)->getUrlData($url);
            $this->fail('getUrlData() returned an oversize response of undeclared length');
        } catch (\Com\Tecnick\File\Exception $exception) {
            // The streaming arm, so the message quotes what was actually read
            // rather than the declared-size wording.
            $this->assertStringContainsString('aborted after', $exception->getMessage());
            $this->assertStringNotContainsString('declared', $exception->getMessage());
        }
    }

    /**
     * maxRemoteSize bounds the bytes that reach PHP memory, not the bytes
     * received, so a compressed response is measured by what it inflates to.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataBoundsTheDecompressedSizeNotTheTransferredSize(): void
    {
        $this->requireLocalHttpServer();

        $url = 'http://127.0.0.1:' . self::$serverPort . '/gzip.php';

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        // Transparent decompression, the ordinary way a caller enables gzip.
        $file->setCurlOpts([CURLOPT_ENCODING => '']);

        // The compressed payload is a few kilobytes, so a limit far above it
        // still has to refuse the megabyte it inflates to.
        $file->setMaxRemoteSize(100_000);

        try {
            $file->getUrlData($url);
            $this->fail('getUrlData() buffered a body larger than maxRemoteSize');
        } catch (\Com\Tecnick\File\Exception $exception) {
            $this->assertStringContainsString('exceeds maximum allowed size of 100000 bytes', $exception->getMessage());
        }

        // With a limit above the inflated size the same response is readable,
        // which pins that the refusal above was the limit and not the encoding.
        $file->setMaxRemoteSize(2_000_000);
        $this->assertSame(1_000_000, \strlen((string) $file->getUrlData($url)));
    }

    /**
     * libcurl prefers CURLOPT_XFERINFOFUNCTION over CURLOPT_PROGRESSFUNCTION
     * when both are set, so a caller supplying the former must not displace
     * the library's size guard.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSizeLimitSurvivesACallerSuppliedTransferInfoCallback(): void
    {
        $this->requireLocalHttpServer();

        if (!\defined('CURLOPT_XFERINFOFUNCTION')) {
            $this->markTestSkipped('CURLOPT_XFERINFOFUNCTION is not available in this libcurl build');
        }

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $file->setMaxRemoteSize(10);
        $file->setCurlOpts([CURLOPT_XFERINFOFUNCTION => static fn(): int => 0]);

        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/large.php');
    }

    /**
     * A caller-supplied write callback must not divert the body: the library
     * assigns its own after the option merge.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSizeLimitSurvivesACallerSuppliedWriteCallback(): void
    {
        $this->requireLocalHttpServer();

        $diverted = '';
        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $file->setMaxRemoteSize(10);
        $file->setCurlOpts([
            CURLOPT_WRITEFUNCTION => static function ($_curlHandle, string $chunk) use (&$diverted): int {
                $diverted .= $chunk;

                return \strlen($chunk);
            },
        ]);

        try {
            $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/large.php');
            $this->fail('a caller write callback displaced the size limit');
        } catch (\Com\Tecnick\File\Exception $exception) {
            $this->assertStringContainsString('exceeds maximum allowed size', $exception->getMessage());
        }

        $this->assertSame('', $diverted);
    }

    /**
     * A Location header on a 200 names no hop libcurl will take, so the
     * response is content.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testLocationHeaderOnANonRedirectResponseIsNotTreatedAsARedirect(): void
    {
        $this->requireLocalHttpServer();

        $url = 'http://127.0.0.1:' . self::$serverPort . '/location200.php';

        // With redirect validation active: the Location points off-allowlist.
        $following = new \Com\Tecnick\File\File(['127.0.0.1']);
        $following->setCurlOpts([CURLOPT_MAXREDIRS => 5]);
        $this->assertSame('PLAIN-200-BODY', $following->getUrlData($url));

        // And with it inactive, so the two agree on the same response.
        $notFollowing = new \Com\Tecnick\File\File(['127.0.0.1']);
        $this->assertSame('PLAIN-200-BODY', $notFollowing->getUrlData($url));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataReturnTrue(): void
    {
        $this->requireLocalHttpServer();

        // A File with no fixed cURL options, so CURLOPT_RETURNTRANSFER is
        // not set and only the write callback captures the body.
        $file = new \Com\Tecnick\File\File(['127.0.0.1'], 52428800, [], [], []);

        \ob_start();
        $result = $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/empty.php');
        $printed = (string) \ob_get_clean();

        $this->assertSame('', $result);
        $this->assertSame('', $printed);
    }

    /**
     * Without CURLOPT_RETURNTRANSFER the body would go to standard output; the
     * write callback keeps it out of there and returns it as content.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataReturnsTheBodyWithoutReturnTransfer(): void
    {
        $this->requireLocalHttpServer();

        $file = new \Com\Tecnick\File\File(['127.0.0.1'], 52428800, [], [], []);

        \ob_start();
        $result = $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/large.php');
        $printed = (string) \ob_get_clean();

        $this->assertSame(1000, \strlen((string) $result));
        $this->assertSame('', $printed, 'the response body must not leak to the output stream');
    }

    /**
     * CURLOPT_URL is assigned after the option merge, so a caller cannot
     * redirect the request away from the URL that isValidURL() checked.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testCallerSuppliedUrlOptionCannotOverrideTheValidatedUrl(): void
    {
        $this->requireLocalHttpServer();

        $validated = 'http://127.0.0.1:' . self::$serverPort . '/identity.php';
        $other = 'http://127.0.0.1:' . self::$serverPort . '/large.php';

        foreach ([
            'through the constructor' => new \Com\Tecnick\File\File(['127.0.0.1'], 52_428_800, [CURLOPT_URL => $other]),
            'through setCurlOpts' => (new \Com\Tecnick\File\File(['127.0.0.1']))->setCurlOpts([CURLOPT_URL => $other]),
        ] as $label => $file) {
            $result = (string) $file->getUrlData($validated);

            $this->assertNotSame(1000, \strlen($result), $label);
            $this->assertSame(self::$serverMarker, $result, $label);
        }
    }

    /**
     * The fixed options are applied last, so a caller cannot disable the
     * security-critical ones through setCurlOpts().
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testCallerCannotDisableTheFixedSecurityOptions(): void
    {
        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $file->setCurlOpts([
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FAILONERROR => false,
        ]);

        $rfm = new \ReflectionMethod($file, 'mergeCurlOptions');
        /** @var array<int, mixed> $merged */
        $merged = $rfm->invoke($file, []);

        foreach ([
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => true,
        ] as $option => $expected) {
            $this->assertArrayHasKey($option, $merged, (string) $option);
            $this->assertSame($expected, $merged[$option] ?? null, (string) $option);
        }
    }

    /**
     * An option whose value this libcurl build rejects makes
     * curl_setopt_array() return false and apply none of the options after it,
     * which is reported as the library exception.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testUnsettableCurlOptionValueIsReportedAsALibraryException(): void
    {
        $file = new \Com\Tecnick\File\File(['example.com']);
        // A recognized option name, so no ValueError; the value is out of range
        // for this build, so curl_setopt_array() returns false instead.
        $file->setCurlOpts([CURLOPT_SSLVERSION => 999_999]);

        try {
            $file->getUrlData('http://example.com/');
            $this->fail('getUrlData() accepted a cURL option value it could not apply');
        } catch (\Com\Tecnick\File\Exception $exception) {
            $this->assertStringContainsString('unable to apply the cURL options', $exception->getMessage());
        }
    }

    /**
     * The cURL path is used even when allow_url_fopen is enabled and the
     * FORCE_CURL constant is not defined.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataWithAllowUrlFopenEnabled(): void
    {
        $this->requireLocalHttpServer();

        if (\defined('FORCE_CURL')) {
            $this->markTestSkipped('FORCE_CURL is defined in this environment');
        }

        if (\ini_get('allow_url_fopen') === false || \ini_get('allow_url_fopen') === '0') {
            $this->markTestSkipped('allow_url_fopen is disabled in this environment');
        }

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);

        $result = $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/empty.php');
        $this->assertSame('', $result);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetUrlDataCurlExecFailureReturnsFalse(): void
    {
        if (!\function_exists('curl_init')) {
            $this->markTestSkipped('cURL extension not available');
        }

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $result = $file->getUrlData('http://127.0.0.1:1/unreachable.txt');
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // Cross-platform: case-sensitivity, binary mode, Unicode, separators
    // -------------------------------------------------------------------------

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllowlistIsCaseInsensitiveWhenOverrideOff(): void
    {
        $proxy = new class() extends \Com\Tecnick\File\File {
            public function isPathAllowedProxy(string $path): bool
            {
                return $this->isPathWithinAllowedRoots($path, $this->allowedPaths);
            }
        };
        $proxy->setAllowedPaths(['/srv/App', 'C:\\Trusted\\Base']);
        $proxy->setCaseSensitivePaths(false); // force case-insensitive matching

        $this->assertTrue($proxy->isPathAllowedProxy('/srv/app/file.txt'));
        $this->assertTrue($proxy->isPathAllowedProxy('/SRV/APP/file.txt'));
        $this->assertTrue($proxy->isPathAllowedProxy('c:/trusted/base/file.txt'));
        $this->assertTrue($proxy->isPathAllowedProxy('C:/TRUSTED/BASE/file.txt'));
        // still rejects a genuinely different sibling root
        $this->assertFalse($proxy->isPathAllowedProxy('/srv/app_evil/file.txt'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllowlistIsCaseSensitiveWhenOverrideOn(): void
    {
        $proxy = new class() extends \Com\Tecnick\File\File {
            public function isPathAllowedProxy(string $path): bool
            {
                return $this->isPathWithinAllowedRoots($path, $this->allowedPaths);
            }
        };
        $proxy->setAllowedPaths(['/srv/App']);
        $proxy->setCaseSensitivePaths(true); // force case-sensitive matching

        $this->assertTrue($proxy->isPathAllowedProxy('/srv/App/file.txt'));
        $this->assertFalse($proxy->isPathAllowedProxy('/srv/app/file.txt'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllowlistDefaultIsCaseSensitiveOnLinux(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux-specific default behavior');
        }

        $proxy = new class() extends \Com\Tecnick\File\File {
            public function isPathAllowedProxy(string $path): bool
            {
                return $this->isPathWithinAllowedRoots($path, $this->allowedPaths);
            }
        };
        $proxy->setAllowedPaths(['/srv/App']); // override left null -> auto-detect

        $this->assertTrue($proxy->isPathAllowedProxy('/srv/App/file.txt'));
        $this->assertFalse($proxy->isPathAllowedProxy('/srv/app/file.txt'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testCaseInsensitiveDefaultPerOsFamily(): void
    {
        $proxy = new class() extends \Com\Tecnick\File\File {
            public ?bool $probeResult = null;

            public function caseInsensitiveDefaultProxy(string $osFamily, string $hint): bool
            {
                return $this->caseInsensitiveDefault($osFamily, $hint);
            }

            protected function probeCaseInsensitive(string $hint): ?bool
            {
                return $this->probeResult;
            }
        };

        // Windows is always case-insensitive, regardless of the probe.
        $proxy->probeResult = null;
        $this->assertTrue($proxy->caseInsensitiveDefaultProxy('Windows', '/whatever'));

        // macOS: probe decides; null falls back to case-insensitive.
        $proxy->probeResult = null;
        $this->assertTrue($proxy->caseInsensitiveDefaultProxy('Darwin', '/whatever'));
        $proxy->probeResult = true;
        $this->assertTrue($proxy->caseInsensitiveDefaultProxy('Darwin', '/whatever'));
        $proxy->probeResult = false;
        $this->assertFalse($proxy->caseInsensitiveDefaultProxy('Darwin', '/whatever'));

        // Linux/other: probe decides; null falls back to case-sensitive.
        $proxy->probeResult = null;
        $this->assertFalse($proxy->caseInsensitiveDefaultProxy('Linux', '/whatever'));
        $proxy->probeResult = true;
        $this->assertTrue($proxy->caseInsensitiveDefaultProxy('Linux', '/whatever'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testProbeCaseInsensitive(): void
    {
        $proxy = new class() extends \Com\Tecnick\File\File {
            public function probeProxy(string $hint): ?bool
            {
                return $this->probeCaseInsensitive($hint);
            }
        };

        // Unresolvable path -> null (caller applies platform default).
        $missing = \sys_get_temp_dir() . '/tc-nope-' . \uniqid('', true);
        $this->assertNull($proxy->probeProxy($missing));

        // Existing path -> concrete bool reflecting the host filesystem.
        $result = $proxy->probeProxy(__FILE__);
        $this->assertIsBool($result);
        if (\PHP_OS_FAMILY === 'Linux') {
            $this->assertFalse($result);
        }

        // When the resolved path has no letter to toggle, the probe cannot
        // decide and returns null.
        $noFlip = new class() extends \Com\Tecnick\File\File {
            public function probeProxy(string $hint): ?bool
            {
                return $this->probeCaseInsensitive($hint);
            }

            protected function flipLastAlphaCase(string $str): string
            {
                return $str; // simulate a path with no ASCII letter
            }
        };
        $this->assertNull($noFlip->probeProxy(__FILE__));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFlipLastAlphaCase(): void
    {
        $proxy = new class() extends \Com\Tecnick\File\File {
            public function flipProxy(string $str): string
            {
                return $this->flipLastAlphaCase($str);
            }
        };

        $this->assertSame('baR', $proxy->flipProxy('bar'));
        $this->assertSame('bar', $proxy->flipProxy('baR'));
        $this->assertSame('foo.txT', $proxy->flipProxy('foo.txt'));
        $this->assertSame('12.34', $proxy->flipProxy('12.34')); // no letter to flip
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testNormalizeUnicodeFoldsNfdToNfc(): void
    {
        if (!\class_exists(\Normalizer::class)) {
            $this->markTestSkipped('ext-intl is not available');
        }

        $proxy = new class() extends \Com\Tecnick\File\File {
            public function normalizeUnicodeProxy(string $str): string
            {
                return $this->normalizeUnicode($str);
            }
        };

        $nfc = "caf\u{00E9}"; // é as a single precomposed code point
        $nfd = "cafe\u{0301}"; // e + combining acute accent

        $this->assertNotSame($nfc, $nfd, 'sanity: the two forms differ as byte strings');
        $this->assertSame($nfc, $proxy->normalizeUnicodeProxy($nfd));
        $this->assertSame($nfc, $proxy->normalizeUnicodeProxy($nfc));

        // Invalid UTF-8 makes Normalizer::normalize() return false; the method
        // must degrade to returning the input unchanged.
        $invalid = "\xff\xfe";
        $this->assertSame($invalid, $proxy->normalizeUnicodeProxy($invalid));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testNormalizeLocalSeparators(): void
    {
        $proxy = new class() extends \Com\Tecnick\File\File {
            public function sepProxy(string $path): string
            {
                return $this->normalizeLocalSeparators($path);
            }
        };

        $this->assertSame('C:/inetpub/wwwroot/path', $proxy->sepProxy('C:\\inetpub\\wwwroot/path'));
        $this->assertSame('/var/www/x', $proxy->sepProxy('/var/www/x'));
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetAltLocalUrlPathNormalizesWindowsDocumentRoot(): void
    {
        $proxy = new class(['localhost']) extends \Com\Tecnick\File\File {
            public function altLocalUrlPathProxy(string $file): string
            {
                return $this->getAltLocalUrlPath($file);
            }
        };

        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = 'C:\\inetpub\\wwwroot';
        try {
            $this->assertSame('C:/inetpub/wwwroot/path/test.txt', $proxy->altLocalUrlPathProxy('/path/test.txt'));
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFopenLocalForcesBinaryMode(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'tcb');
        $this->assertIsString($tmp);
        \file_put_contents($tmp, 'data');

        // Wildcard allowlist keeps the focus on the mode handling.
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);

        try {
            $handle = $file->fopenLocal($tmp, 'r');
            $meta = \stream_get_meta_data($handle);
            $this->assertSame('rb', $meta['mode']);
            \fclose($handle);

            // An explicit binary mode is preserved (not doubled).
            $handle = $file->fopenLocal($tmp, 'rb');
            $meta = \stream_get_meta_data($handle);
            $this->assertSame('rb', $meta['mode']);
            \fclose($handle);
        } finally {
            \unlink($tmp);
        }
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     */
    public function testStripFileScheme(): void
    {
        $proxy = new class() extends \Com\Tecnick\File\File {
            public function stripProxy(string $file): string
            {
                return $this->stripFileScheme($file);
            }
        };

        // POSIX absolute: the empty-host 'file:///...' form yields a plain path.
        $this->assertSame('/etc/hosts', $proxy->stripProxy('file:///etc/hosts'));
        // Windows drive path: must become the bare path, NOT 'file://C:\...'
        // (which PHP would parse with host "C:" and fail to open).
        $this->assertSame('C:/Users/me/doc.txt', $proxy->stripProxy('file://C:/Users/me/doc.txt'));
        $this->assertSame('C:\\Users\\me\\doc.txt', $proxy->stripProxy('file://C:\\Users\\me\\doc.txt'));
        // A value without the scheme is returned unchanged.
        $this->assertSame('/plain/path', $proxy->stripProxy('/plain/path'));
    }

    /**
     * A 'file://<scheme>://...' input must be rejected even under wildcard
     * trust, so stripping the 'file://' scheme cannot expose an attacker-chosen
     * stream wrapper (php:// arbitrary read, http:// SSRF, phar://, data://) to
     * fopen()/file_get_contents().
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatePathRejectsNestedStreamWrapper(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'tcw');
        $this->assertIsString($tmp);
        \file_put_contents($tmp, 'SECRET-CONTENT');

        // Wildcard trust: the nested-scheme guard must still reject these.
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);

        $vectors = [
            'file://php://filter/convert.base64-encode/resource=' . $tmp,
            'file://phar://archive.phar/payload',
            'file://http://example.com/resource',
            'file://data://text/plain;base64,U0VDUkVU',
            // The data wrapper also fires WITHOUT '://' (bare 'data:' form),
            // which a plain '://' check would miss.
            'file://data:text/plain;base64,U0VDUkVU',
            'file://data:text/plain,SECRET',
        ];

        try {
            foreach ($vectors as $vector) {
                $candidate = $vector;
                $this->assertFalse($file->isValidFile($candidate), 'isValidFile: ' . $vector);
                $this->assertFalse($file->getLocalFileData($vector), 'getLocalFileData: ' . $vector);
            }
        } finally {
            \unlink($tmp);
        }
    }

    /**
     * The validated path handed to the filesystem must be the bare path, so a
     * real read succeeds (on Windows the 'file://C:\...' form would not open).
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetLocalFileDataReadsValidatedPath(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'tcr');
        $this->assertIsString($tmp);
        \file_put_contents($tmp, 'hello-bytes');

        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);

        try {
            $this->assertSame('hello-bytes', $file->getLocalFileData($tmp));
        } finally {
            \unlink($tmp);
        }
    }

    /**
     * Wildcard host trust is a documented escape hatch, so its effect must be
     * pinned: it accepts every host, and it must not reach any other allowlist.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWildcardHostTrustAcceptsAnyHost(): void
    {
        $file = new \Com\Tecnick\File\File(['*']);

        foreach ([
            'https://anything.example/a',
            'http://127.0.0.1/a',
            'https://sub.domain.example.org:8443/a',
            'http://[::1]:9000/a',
        ] as $url) {
            $this->assertTrue($file->isAllowedUrl($url), $url);
        }
    }

    /**
     * '*' is preserved verbatim by the host normalizer: rtrim()ing its trailing
     * characters the way a real hostname is treated would destroy the marker.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWildcardHostSurvivesNormalization(): void
    {
        $file = new class(['  *  ']) extends \Com\Tecnick\File\File {
            /**
             * @return array<string>
             */
            public function allowedHostsProxy(): array
            {
                return $this->allowedHosts;
            }
        };

        $this->assertSame(['*'], $file->allowedHostsProxy());
        $this->assertTrue($file->isAllowedUrl('https://anything.example/a'));
    }

    /**
     * The two allowlists are independent: trusting every host must not grant any
     * filesystem access, and trusting every path must not open the network.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testWildcardHostTrustDoesNotRelaxThePathAllowlist(): void
    {
        $hostWildcard = new \Com\Tecnick\File\File(['*']);
        $this->assertFalse($hostWildcard->isAllowedFile(__FILE__));

        $pathWildcard = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);
        $this->assertFalse($pathWildcard->isAllowedUrl('https://anything.example/a'));
        $this->assertTrue($pathWildcard->isAllowedFile(__FILE__));
    }

    /**
     * An allowlist entry carrying a port authorizes that origin in a URL too,
     * and only that port. A host-only entry stays port-agnostic.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testUrlAllowlistMatchesThePortWhenTheEntryCarriesOne(): void
    {
        $withPort = new \Com\Tecnick\File\File(['example.com:8080']);
        $this->assertTrue($withPort->isAllowedUrl('https://example.com:8080/a'));
        $this->assertTrue($withPort->isAllowedUrl('http://example.com:8080/a'));
        $this->assertFalse($withPort->isAllowedUrl('https://example.com/a'));
        $this->assertFalse($withPort->isAllowedUrl('https://example.com:9090/a'));

        // A URL that omits the port is matched against the scheme default.
        $httpsDefault = new \Com\Tecnick\File\File(['example.com:443']);
        $this->assertTrue($httpsDefault->isAllowedUrl('https://example.com/a'));
        $this->assertFalse($httpsDefault->isAllowedUrl('http://example.com/a'));

        $httpDefault = new \Com\Tecnick\File\File(['example.com:80']);
        $this->assertTrue($httpDefault->isAllowedUrl('http://example.com/a'));
        $this->assertFalse($httpDefault->isAllowedUrl('https://example.com/a'));

        $hostOnly = new \Com\Tecnick\File\File(['example.com']);
        $this->assertTrue($hostOnly->isAllowedUrl('https://example.com/a'));
        $this->assertTrue($hostOnly->isAllowedUrl('https://example.com:8080/a'));
        $this->assertFalse($hostOnly->isAllowedUrl('https://evil.example/a'));
    }

    /**
     * An empty file name names nothing: it must not raise a PHP warning through
     * a string offset, and it must not turn into a bare-origin URL candidate
     * that would fetch the site root of SCRIPT_URI.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testEmptyFileNameYieldsNoCandidates(): void
    {
        $_SERVER['SCRIPT_URI'] = 'https://example.com/index.php';
        $file = new \Com\Tecnick\File\File(['example.com']);

        $this->assertSame([], $file->getAltFilePaths(''));

        $this->expectException(\Com\Tecnick\File\Exception::class);
        $file->fileGetContents('');
    }

    /**
     * A non-positive limit would abort every transfer in the progress callback,
     * so it is rejected where it is set rather than where it is used.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testMaxRemoteSizeRejectsNonPositiveValues(): void
    {
        $file = new \Com\Tecnick\File\File();

        foreach ([0, -1] as $invalid) {
            $thrown = null;

            try {
                $file->setMaxRemoteSize($invalid);
            } catch (\Com\Tecnick\File\Exception $exception) {
                $thrown = $exception;
            }

            $this->assertInstanceOf(\Com\Tecnick\File\Exception::class, $thrown, (string) $invalid);
        }

        // The rejected calls left the previous limit in place.
        $this->assertSame(52_428_800, $file->getMaxRemoteSize());

        $this->expectException(\Com\Tecnick\File\Exception::class);
        new \Com\Tecnick\File\File([], 0);
    }

    /**
     * rfRead() reports a non-positive length distinctly, instead of draining
     * nothing and reporting an unreadable file.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testReadRejectsNonPositiveLength(): void
    {
        $file = new \Com\Tecnick\File\File();
        $handle = \fopen('php://memory', 'r+b');
        $this->assertIsResource($handle);
        \fwrite($handle, 'abcd');
        \rewind($handle);

        try {
            // The handle is readable, so a failure here can only come from the
            // length check.
            $this->assertSame('ab', $file->rfRead($handle, 2));
            \rewind($handle);

            $thrown = null;

            try {
                $file->rfRead($handle, 0);
            } catch (\Com\Tecnick\File\Exception $exception) {
                $thrown = $exception;
            }

            $this->assertInstanceOf(\Com\Tecnick\File\Exception::class, $thrown);
            // The message names the length check, not a read failure.
            $this->assertStringContainsString('must be positive', $thrown->getMessage());
        } finally {
            \fclose($handle);
        }
    }

    /**
     * A leading Windows drive designator is not a stream wrapper prefix, while
     * any other leading 'name:' is treated as one whatever its length.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSchemeGuardExemptsWindowsDrivesConsistently(): void
    {
        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['*']);

        foreach (['C:/dir/x', 'c:\\dir\\x', 'D:/x'] as $drive) {
            $this->assertTrue($file->isAllowedFile($drive), $drive);
        }

        foreach (['a:b', 'report:v1.txt', 'php://input', 'data:x', 'phar:x'] as $rejected) {
            $this->assertFalse($file->isAllowedFile($rejected), $rejected);
        }
    }

    /**
     * Redundant separators and '.' segments name the same location, so a root or
     * a candidate written with them must still match.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAllowlistIgnoresRedundantPathSegments(): void
    {
        $base = $this->makeTempDir();
        $sub = $base . \DIRECTORY_SEPARATOR . 'sub';
        $this->assertTrue(\mkdir($sub, 0o777, true));
        $target = $sub . \DIRECTORY_SEPARATOR . 'a.txt';
        \file_put_contents($target, 'x');

        try {
            foreach ([$base, $base . '/', $base . '//', $base . '/./'] as $root) {
                $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$root]);
                $this->assertTrue($file->isAllowedFile($target), $root);
            }

            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$base]);
            foreach ([
                $base . '//sub//a.txt',
                $base . '/./sub/a.txt',
                $base . '/sub/./a.txt',
            ] as $candidate) {
                $this->assertTrue($file->isAllowedFile($candidate), $candidate);
            }

            // Collapsing must not open a traversal route.
            $this->assertFalse($file->isAllowedFile($base . '/sub/../../etc/passwd'));
        } finally {
            \unlink($target);
            \rmdir($sub);
            \rmdir($base);
        }
    }

    /**
     * The redirect header callback falls back to the initial URL when cURL has
     * no effective URL to report yet.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRedirectValidationCallbackFallsBackToTheInitialUrl(): void
    {
        // A fresh handle reports HTTP code 0, so isRedirectStatus() is forced
        // here to make the callback validate the Location header.
        $file = self::fileTreatingEveryResponseAsARedirect(['example.com']);

        $rfm = new \ReflectionMethod($file, 'createRedirectValidationCallback');

        // A fresh handle has no URL set, so CURLINFO_EFFECTIVE_URL is '' and the
        // relative target can only be resolved against the initial URL.
        $handle = \curl_init();
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        $this->assertSame('', (string) \curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));

        $acceptedFlag = false;
        $args = [&$acceptedFlag, 'https://example.com/start'];
        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);

        $header = "Location: /next\r\n";
        $this->assertSame(\strlen($header), $callback($handle, $header));
        $this->assertFalse($acceptedFlag);

        // Resolved against the same initial URL, an off-host target is refused.
        $rejectedFlag = false;
        $args = [&$rejectedFlag, 'https://example.com/start'];
        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);

        $this->assertSame(0, $callback($handle, "Location: https://evil.example/next\r\n"));
        $this->assertTrue($rejectedFlag);
    }

    /**
     * A File whose isRedirectStatus() always answers true, so the redirect
     * validation callback can be driven without a live 3xx response.
     *
     * @param array<string> $allowedHosts Trusted hostnames.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    private static function fileTreatingEveryResponseAsARedirect(array $allowedHosts): \Com\Tecnick\File\File
    {
        return new class($allowedHosts) extends \Com\Tecnick\File\File {
            protected function isRedirectStatus(\CurlHandle $curlHandle): bool
            {
                return true;
            }
        };
    }

    /**
     * libcurl acts on Location only for a 3xx response. The header is legal
     * on others, where it names no hop that will be taken, so it is ignored
     * there rather than validated.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testRedirectValidationCallbackIgnoresLocationOnANonRedirectStatus(): void
    {
        $file = new \Com\Tecnick\File\File(['example.com']);

        $rfm = new \ReflectionMethod($file, 'createRedirectValidationCallback');

        // A fresh handle reports HTTP code 0, which is not a 3xx.
        $handle = \curl_init();
        $this->assertInstanceOf(\CurlHandle::class, $handle);

        $invalidRedirect = false;
        $args = [&$invalidRedirect, 'https://example.com/start'];
        /** @var callable $callback */
        $callback = $rfm->invokeArgs($file, $args);

        // The very target that a 3xx would be rejected for.
        $header = "Location: https://evil.example/next\r\n";
        $this->assertSame(\strlen($header), $callback($handle, $header));
        $this->assertFalse($invalidRedirect);

        // An empty Location is likewise none of this callback's business.
        $empty = "Location: \r\n";
        $this->assertSame(\strlen($empty), $callback($handle, $empty));
        $this->assertFalse($invalidRedirect);

        // On a 3xx the same empty header is a redirect with no target, which
        // cannot be validated and so aborts the transfer.
        $redirectingToNowhere = self::fileTreatingEveryResponseAsARedirect(['example.com']);
        $rfmNowhere = new \ReflectionMethod($redirectingToNowhere, 'createRedirectValidationCallback');
        $noTarget = false;
        $args = [&$noTarget, 'https://example.com/start'];
        /** @var callable $callback */
        $callback = $rfmNowhere->invokeArgs($redirectingToNowhere, $args);

        $this->assertSame(0, $callback($handle, $empty));
        $this->assertTrue($noTarget);

        // The same handle, once the response counts as a redirect, rejects it.
        $redirecting = self::fileTreatingEveryResponseAsARedirect(['example.com']);
        $rfmRedirecting = new \ReflectionMethod($redirecting, 'createRedirectValidationCallback');
        $rejected = false;
        $args = [&$rejected, 'https://example.com/start'];
        /** @var callable $callback */
        $callback = $rfmRedirecting->invokeArgs($redirecting, $args);

        $this->assertSame(0, $callback($handle, $header));
        $this->assertTrue($rejected);
    }

    /**
     * With no base directories and an unresolvable relative path, the input is
     * returned unchanged.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testResolveLocalPathWithoutBaseDirsReturnsInputUnchanged(): void
    {
        $file = new \Com\Tecnick\File\File();
        $missing = 'no-such-dir-' . \uniqid('', true) . '/missing.txt';

        $this->assertSame($missing, $file->resolveLocalPath($missing));
    }

    /**
     * A 3xx that cURL was not asked to follow is a failure, not content: without
     * this the body of the redirect response would be returned as the file.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testIsRedirectStatusIsFalseBeforeAnyTransfer(): void
    {
        $file = new class() extends \Com\Tecnick\File\File {
            public function isRedirectStatusProxy(\CurlHandle $curlHandle): bool
            {
                return $this->isRedirectStatus($curlHandle);
            }
        };

        $handle = \curl_init();
        $this->assertInstanceOf(\CurlHandle::class, $handle);

        // No transfer has run, so CURLINFO_HTTP_CODE is 0: not a redirect.
        $this->assertFalse($file->isRedirectStatusProxy($handle));
    }

    /**
     * A 3xx reached with redirect following switched off is a failure, not
     * content. This is the state open_basedir forces on every request, and
     * without the check the body of the redirect response would be returned.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testUnfollowedRedirectIsNotReturnedAsContent(): void
    {
        $this->requireLocalHttpServer();

        $url = 'http://127.0.0.1:' . self::$serverPort . '/redirect.php';

        $notFollowing = new \Com\Tecnick\File\File(['127.0.0.1'], 52_428_800, [CURLOPT_FOLLOWLOCATION => false]);

        // The transfer itself succeeds with a 302 and an empty body; only the
        // status check distinguishes that from a genuinely empty file.
        $this->assertFalse($notFollowing->getUrlData($url));

        // A non-redirect response over the same configuration still comes back.
        $this->assertSame('', $notFollowing->getUrlData('http://127.0.0.1:' . self::$serverPort . '/empty.php'));
    }

    /**
     * A host that normalizes to the empty string trusts nothing, even under
     * wildcard path trust.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testUrlHostThatNormalizesToEmptyIsRejected(): void
    {
        $file = new \Com\Tecnick\File\File(['*']);

        // normalizeHost() strips trailing root dots, so a dots-only host folds
        // to '' and cannot match, wildcard or not.
        $this->assertFalse($file->isAllowedUrl('http://./a'));
        $this->assertFalse($file->isAllowedUrl('http://.../a'));
    }

    /**
     * Path normalization keeps the forms that carry meaning: a leading '//' is
     * preserved, and an input made only of '.' segments stays relative.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testPathNormalizationPreservesMeaningfulForms(): void
    {
        $file = new class() extends \Com\Tecnick\File\File {
            public function normalizeProxy(string $path): string
            {
                return $this->normalizePathForComparison($path);
            }
        };

        // Redundant separators and '.' segments collapse.
        $this->assertSame('/srv/data', $file->normalizeProxy('/srv//data'));
        $this->assertSame('/srv/data', $file->normalizeProxy('/srv/./data'));
        $this->assertSame('/srv/data', $file->normalizeProxy('/srv/././/data//'));
        $this->assertSame('/', $file->normalizeProxy('/'));

        // A leading '//' is implementation-defined on POSIX and is the UNC form
        // on Windows once separators are normalized, so it is not collapsed.
        $this->assertSame('//server/share', $file->normalizeProxy('//server/share'));
        $this->assertSame('//server/share', $file->normalizeProxy('//server//./share'));

        // Three or more leading slashes carry no such meaning.
        $this->assertSame('/server/share', $file->normalizeProxy('///server/share'));

        // A relative input of only '.' segments still names the current directory.
        $this->assertSame('.', $file->normalizeProxy('.'));
        $this->assertSame('.', $file->normalizeProxy('./'));
        $this->assertSame('.', $file->normalizeProxy('././.'));
    }

    /**
     * A NUL byte truncates a path at the C level and makes every PHP path
     * function throw a ValueError. The validator must reject it and keep its
     * bool contract, so the readers keep theirs.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testNulByteInAPathIsRejectedWithoutRaisingAValueError(): void
    {
        $dir = self::makeTempDir();
        $good = $dir . \DIRECTORY_SEPARATOR . 'ok.txt';
        $this->assertNotFalse(\file_put_contents($good, 'data'));

        try {
            $file = new \Com\Tecnick\File\File(allowedPaths: [$dir]);
            $poisoned = $good . "\0evil";

            // The positive control: the same path without the NUL byte reads.
            $this->assertSame('data', $file->getLocalFileData($good));

            $this->assertFalse($file->isAllowedFile($poisoned));
            $this->assertFalse($file->isAllowedFile("a\0b"));
            $this->assertFalse($file->getLocalFileData($poisoned));
            $this->assertFalse($file->getFileData($poisoned));
            $this->assertSame("a\0b", $file->resolveLocalPath("a\0b", [$dir]));

            $this->expectException(\Com\Tecnick\File\Exception::class);
            $file->fopenLocal($poisoned, 'r');
        } finally {
            \unlink($good);
            \rmdir($dir);
        }
    }

    /**
     * fileGetContents() reports an unreadable NUL-bearing path as a library
     * exception, not as the ValueError the path functions would raise.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testNulByteInAPathReachesFileGetContentsAsALibraryException(): void
    {
        $file = new \Com\Tecnick\File\File(allowedPaths: ['*']);

        try {
            $file->fileGetContents("/tmp/ok.txt\0evil");
            $this->fail('fileGetContents() accepted a path containing a NUL byte');
        } catch (\Com\Tecnick\File\Exception $exception) {
            $this->assertStringStartsWith('unable to read the file', $exception->getMessage());
        }
    }

    /**
     * parse_url() tolerates C0 controls and DEL, so a URL carrying CR/LF would
     * otherwise validate. A caller may pass a validated URL to its own client
     * or emit it into a header, where those characters split the response.
     *
     * @param string $url      URL to validate.
     * @param bool   $expected Expected result.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[DataProvider('controlCharacterUrlProvider')]
    public function testIsAllowedUrlRejectsControlCharacters(string $url, bool $expected): void
    {
        $file = new \Com\Tecnick\File\File(['example.com']);

        $this->assertSame($expected, $file->isAllowedUrl($url));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function controlCharacterUrlProvider(): array
    {
        return [
            'clean url' => ['http://example.com/a', true],
            'crlf in path' => ["http://example.com/a\r\nHost: evil", false],
            'bare lf' => ["http://example.com/a\nx", false],
            'bare cr' => ["http://example.com/a\rx", false],
            'tab in path' => ["http://example.com/\tx", false],
            'nul byte' => ["http://example.com/\0x", false],
            'del' => ["http://example.com/\x7Fx", false],
            'vertical tab' => ["http://example.com/\x0Bx", false],
        ];
    }

    /**
     * A cURL option this build does not recognize makes curl_setopt_array()
     * raise a ValueError. getUrlData() is documented to return string|false and
     * to throw the library exception, so the error is converted rather than
     * escaping as a PHP error.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testInvalidCurlOptionIsReportedAsALibraryException(): void
    {
        $file = new \Com\Tecnick\File\File(['example.com']);
        $file->setCurlOpts([999_999 => 1]);

        try {
            $file->getUrlData('http://example.com/');
            $this->fail('getUrlData() accepted an unrecognized cURL option');
        } catch (\Com\Tecnick\File\Exception $exception) {
            $this->assertStringStartsWith('invalid cURL option', $exception->getMessage());
        }
    }

    /**
     * An undecidable probe says nothing about the volume, so it is not cached
     * for the containing directory and a later path there is probed again.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testAnUndecidableCaseProbeIsNotCachedForTheDirectory(): void
    {
        $dir = self::makeTempDir();
        $existing = $dir . \DIRECTORY_SEPARATOR . 'Real.txt';
        $this->assertNotFalse(\file_put_contents($existing, 'x'));

        try {
            $proxy = new class() extends \Com\Tecnick\File\File {
                public function probeProxy(string $hint): ?bool
                {
                    return $this->probeCaseInsensitive($hint);
                }
            };

            $missing = $dir . \DIRECTORY_SEPARATOR . 'missing.txt';

            // Probing a path that does not resolve cannot decide.
            $this->assertNull($proxy->probeProxy($missing));

            // The existing sibling must still get a real answer rather than the
            // cached null, so it agrees with a cold instance.
            $cold = new class() extends \Com\Tecnick\File\File {
                public function probeProxy(string $hint): ?bool
                {
                    return $this->probeCaseInsensitive($hint);
                }
            };

            $this->assertSame($cold->probeProxy($existing), $proxy->probeProxy($existing));
            $this->assertNotNull($proxy->probeProxy($existing));
        } finally {
            \unlink($existing);
            \rmdir($dir);
        }
    }

    /**
     * Only the leading origin becomes the document root: a later occurrence of
     * the same origin inside the path names no server path.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testGetAltPathFromUrlReplacesTheLeadingOriginOnly(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['DOCUMENT_ROOT'] = '/var/www';
        unset($_SERVER['HTTPS']);

        $proxy = new class(['example.com']) extends \Com\Tecnick\File\File {
            public function altPathProxy(string $url): string
            {
                return $this->getAltPathFromUrl($url);
            }
        };

        $this->assertSame('/var/www/a/b.png', $proxy->altPathProxy('http://example.com/a/b.png'));
        $this->assertSame(
            '/var/www/a/http://example.com/b.png',
            $proxy->altPathProxy('http://example.com/a/http://example.com/b.png'),
        );
    }

    /**
     * The warning-suppression helper swallows E_WARNING and E_NOTICE only.
     * Every other level still reaches the handler the application installed.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSuppressionDoesNotDetachTheApplicationErrorHandler(): void
    {
        \stream_wrapper_register('tclfdeprecating', DeprecatingStreamWrapper::class);

        $seen = [];
        \set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
            $seen[] = [$errno, $errstr];
            return true;
        });

        try {
            $handle = \fopen('tclfdeprecating://x', 'rb');
            $this->assertIsResource($handle);

            try {
                // rfRead() runs fread() inside withoutPhpWarnings().
                $this->assertSame('ok', $this->getTestObject()->rfRead($handle, 2));
            } finally {
                \fclose($handle);
            }
        } finally {
            \restore_error_handler();
            \stream_wrapper_unregister('tclfdeprecating');
        }

        $this->assertSame([[E_USER_DEPRECATED, DeprecatingStreamWrapper::MESSAGE]], $seen);
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
        \stream_wrapper_register('tclfdeprecating2', DeprecatingStreamWrapper::class);

        $level = \error_reporting(E_ALL & ~E_USER_DEPRECATED);
        \set_error_handler(null);

        // PHP's own handler records the diagnostic in error_get_last(), while
        // a swallowed one leaves this sentinel as the last error.
        \trigger_error('SENTINEL-BEFORE', E_USER_DEPRECATED);

        try {
            $handle = \fopen('tclfdeprecating2://x', 'rb');
            $this->assertIsResource($handle);

            try {
                $this->assertSame('ok', $this->getTestObject()->rfRead($handle, 2));
            } finally {
                \fclose($handle);
            }
        } finally {
            \restore_error_handler();
            \error_reporting($level);
            \stream_wrapper_unregister('tclfdeprecating2');
        }

        $last = \error_get_last();
        $this->assertIsArray($last);
        $this->assertSame(DeprecatingStreamWrapper::MESSAGE, $last['message']);
    }

    /**
     * With open_basedir in effect CURLOPT_FOLLOWLOCATION is left unset, so a
     * 3xx completes with CURLE_OK and is reported as an unreadable URL by the
     * unfollowed-redirect check.
     *
     * Redirect following is enabled here, since at the default
     * CURLOPT_MAXREDIRS of 0 libcurl refuses every redirect anyway.
     *
     * Runs in a separate process, because open_basedir cannot be relaxed once
     * it is set.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRedirectUnderOpenBasedirIsNotReturnedAsContent(): void
    {
        $this->requireLocalHttpServer();

        $port = self::$serverPort;
        $redirect = 'http://127.0.0.1:' . $port . '/redirect.php?to=' . \rawurlencode('/large.php');

        // Positive control, before the restriction is in place: with redirects
        // allowed the target's 1 000-byte body is what comes back.
        $unrestricted = new \Com\Tecnick\File\File(['127.0.0.1']);
        $unrestricted->setCurlOpts([CURLOPT_MAXREDIRS => 5]);
        $this->assertSame(1000, \strlen((string) $unrestricted->getUrlData($redirect)));

        // @mago-expect lint:no-ini-set -- open_basedir can only be set at runtime in a test.
        \ini_set('open_basedir', \dirname(__DIR__) . PATH_SEPARATOR . \sys_get_temp_dir());
        $this->assertNotSame('', (string) \ini_get('open_basedir'));

        $file = new \Com\Tecnick\File\File(['127.0.0.1']);
        $file->setCurlOpts([CURLOPT_MAXREDIRS => 5]);

        // A plain response is still readable under the restriction, so a false
        // below is the 3xx being refused and not the transfer failing.
        $this->assertSame('PLAIN-200-BODY', $file->getUrlData('http://127.0.0.1:' . $port . '/location200.php'));

        $this->assertFalse(
            $file->getUrlData($redirect),
            'a 3xx that cURL was not asked to follow must not be returned as content',
        );
    }

    // -------------------------------------------------------------------------
    // URL scheme allowlist
    // -------------------------------------------------------------------------

    /**
     * isValidURL() accepts http and https only, which is what isAllowedUrl()
     * reports for a URL the caller means to hand to a client of its own.
     *
     * @param string $url      URL to validate.
     * @param bool   $expected Expected result.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    #[DataProvider('urlSchemeProvider')]
    public function testIsAllowedUrlAcceptsOnlyHttpSchemes(string $url, bool $expected): void
    {
        $file = new \Com\Tecnick\File\File(['example.com']);

        $this->assertSame($expected, $file->isAllowedUrl($url));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function urlSchemeProvider(): array
    {
        return [
            'http' => ['http://example.com/f.txt', true],
            'https' => ['https://example.com/f.txt', true],
            'uppercase scheme is not the http wrapper' => ['HTTP://example.com/f.txt', false],
            'ftp' => ['ftp://example.com/f.txt', false],
            'gopher' => ['gopher://example.com/f.txt', false],
            'file with a host' => ['file://example.com/f.txt', false],
            'php stream wrapper' => ['php://filter/resource=example.com', false],
            'data uri' => ['data:text/plain;base64,ZXhhbXBsZS5jb20=', false],
            'scheme relative' => ['//example.com/f.txt', false],
            'scheme less' => ['example.com/f.txt', false],
            'bare host' => ['example.com', false],
        ];
    }

    /**
     * An allowlist entry naming an origin ('example.com:80') authorizes that
     * origin in a URL, but does not authorize the bare hostname as an
     * HTTP_HOST value, which requires a host-only entry.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testHostOnlyValidationDoesNotAcceptAnOriginEntry(): void
    {
        $file = new \Com\Tecnick\File\File(['example.com:80']);

        $rfm = new \ReflectionMethod($file, 'isValidHost');

        $this->assertFalse($rfm->invoke($file, 'example.com'));
        $this->assertTrue($rfm->invoke($file, 'example.com:80'));

        // The same entry does authorize the matching origin in a URL, since a
        // URL carries the port that an HTTP_HOST value may omit.
        $this->assertTrue($file->isAllowedUrl('http://example.com/f.txt'));
    }

    // -------------------------------------------------------------------------
    // Path allowlist details
    // -------------------------------------------------------------------------

    /**
     * A symlink that lives outside every allowed root but points inside one is
     * rejected by the raw-path check, since its canonical form is inside a
     * root and passes the canonical one.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testSymlinkOutsideTheRootsPointingInsideIsRejected(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $root = $base . \DIRECTORY_SEPARATOR . 'root';
        $outside = $base . \DIRECTORY_SEPARATOR . 'outside';
        $target = $root . \DIRECTORY_SEPARATOR . 'data.txt';
        $link = $outside . \DIRECTORY_SEPARATOR . 'inward.txt';

        $this->assertTrue(\mkdir($root, 0o777, true));
        $this->assertTrue(\mkdir($outside, 0o777, true));
        $this->assertNotFalse(\file_put_contents($target, 'REAL'));

        if (!self::trySymlink($target, $link)) {
            \unlink($target);
            \rmdir($outside);
            \rmdir($root);
            \rmdir($base);
            $this->markTestSkipped('symlink() not permitted in this environment');
        }

        try {
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$root]);

            // Positive control: the target itself is readable through the root.
            $this->assertTrue($file->isAllowedFile($target));
            $this->assertSame('REAL', $file->getLocalFileData($target));

            // The link resolves to that same file, but is addressed from
            // outside every root, so it is not an allowed path.
            $this->assertFalse($file->isAllowedFile($link));
            $this->assertFalse($file->getLocalFileData($link));
        } finally {
            self::removeSymlink($link);
            \unlink($target);
            \rmdir($outside);
            \rmdir($root);
            \rmdir($base);
        }
    }

    /**
     * The path handed to fopen()/file_get_contents() is the canonical one, so a
     * component cannot be swapped for a symlink between the check and the open.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testValidatedPathIsResolvedToItsCanonicalForm(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $real = $base . \DIRECTORY_SEPARATOR . 'real';
        $link = $base . \DIRECTORY_SEPARATOR . 'link';
        $target = $real . \DIRECTORY_SEPARATOR . 'data.txt';

        $this->assertTrue(\mkdir($real, 0o777, true));
        $this->assertNotFalse(\file_put_contents($target, 'REAL'));

        if (!self::trySymlink($real, $link)) {
            \unlink($target);
            \rmdir($real);
            \rmdir($base);
            $this->markTestSkipped('symlink() not permitted in this environment');
        }

        try {
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$base]);
            $rfm = new \ReflectionMethod($file, 'resolveValidatedPath');

            $throughLink = $link . \DIRECTORY_SEPARATOR . 'data.txt';
            /** @var string $resolved */
            $resolved = $rfm->invoke($file, $throughLink);

            $this->assertNotSame($throughLink, $resolved);
            $this->assertSame(\realpath($target), $resolved);

            // A path that does not resolve is returned as given: that is the
            // case isValidFile() validated through its nearest existing
            // ancestor, for a file about to be created.
            $missing = $real . \DIRECTORY_SEPARATOR . 'not-created-yet.txt';
            $this->assertSame($missing, $rfm->invoke($file, $missing));
        } finally {
            self::removeSymlink($link);
            \unlink($target);
            \rmdir($real);
            \rmdir($base);
        }
    }

    /**
     * A 'file://' reference is trimmed before it is validated, so the path that
     * is opened is exactly the one that was checked.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testFileSchemeReferenceIsTrimmedBeforeItIsOpened(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $target = $base . \DIRECTORY_SEPARATOR . 'data.txt';

        $this->assertTrue(\mkdir($base, 0o777, true));
        $this->assertNotFalse(\file_put_contents($target, 'CONTENT'));

        try {
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$base]);

            $this->assertSame('CONTENT', $file->getLocalFileData('file://' . $target));
            $this->assertSame('CONTENT', $file->getLocalFileData('file:// ' . $target));
            $this->assertSame('CONTENT', $file->getLocalFileData('file://' . $target . ' '));
        } finally {
            \unlink($target);
            \rmdir($base);
        }
    }

    /**
     * A path carrying an embedded '://' is refused even when it does not look
     * like a leading scheme, so nothing that could act as a stream wrapper
     * reaches a reader.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testEmbeddedSchemeSeparatorIsRejected(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $this->assertTrue(\mkdir($base, 0o777, true));

        try {
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$base]);

            // Not a leading 'name:' (the first segment is a plain directory),
            // so only the '://' test refuses it.
            $this->assertFalse($file->isAllowedFile('file://' . $base . '/a://b'));

            // Positive control: the same shape without the separator is allowed.
            $this->assertTrue($file->isAllowedFile('file://' . $base . '/a/b'));
        } finally {
            \rmdir($base);
        }
    }

    /**
     * A DOCUMENT_ROOT already present at the start of the path names the same
     * file, so it is not prefixed a second time: a match at offset 1 counts as
     * already rooted, a match further in does not.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDocumentRootIsNotPrefixedWhenThePathAlreadyCarriesIt(): void
    {
        $file = $this->getTestObject();

        // strpos() finds 'var/www' at offset 1, so the path is already rooted.
        $_SERVER['DOCUMENT_ROOT'] = 'var/www';
        $this->assertSame(['/var/www/x.txt'], $file->getAltFilePaths('/var/www/x.txt'));

        // The same root found deeper in is a coincidence, not a prefix, so the
        // rooted candidate is added.
        $this->assertContains('var/www/deeper/var/www/x.txt', $file->getAltFilePaths('/deeper/var/www/x.txt'));
    }

    /**
     * HTTPS is compared case-insensitively: a server that reports 'Off' means
     * the same as one that reports 'off'.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testDefaultUrlProtocolComparesTheHttpsFlagCaseInsensitively(): void
    {
        $file = $this->getTestObject();
        $rfm = new \ReflectionMethod($file, 'getDefaultUrlProtocol');

        foreach (['off', 'Off', 'OFF', 'oFf'] as $value) {
            $_SERVER['HTTPS'] = $value;
            $this->assertSame('http', $rfm->invoke($file), $value);
        }

        foreach (['on', 'On', '1'] as $value) {
            $_SERVER['HTTPS'] = $value;
            $this->assertSame('https', $rfm->invoke($file), $value);
        }
    }

    /**
     * A read that returns nothing at all is a failure, not an empty string: the
     * callers of rfRead() cannot tell the two apart from the return value.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testReadOfAnEmptyFileThrows(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tclf_' . \uniqid('', true);
        $target = $base . \DIRECTORY_SEPARATOR . 'empty.bin';

        $this->assertTrue(\mkdir($base, 0o777, true));
        $this->assertNotFalse(\file_put_contents($target, ''));

        try {
            $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, [$base]);
            $handle = $file->fopenLocal($target, 'rb');

            try {
                $this->expectException(\Com\Tecnick\File\Exception::class);
                $file->rfRead($handle, 4);
            } finally {
                \fclose($handle);
            }
        } finally {
            \unlink($target);
            \rmdir($base);
        }
    }

    /**
     * Case-insensitive comparison folds the whole of Unicode where ext-mbstring
     * is available: on a case-insensitive volume '/srv/CAFÉ' and '/srv/café'
     * name the same file, so an allowlist written in one case must match a
     * candidate written in the other.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testCaseInsensitiveMatchingFoldsNonAsciiPaths(): void
    {
        if (!\function_exists('mb_strtolower')) {
            $this->markTestSkipped('ext-mbstring is not available in this environment');
        }

        $file = new \Com\Tecnick\File\File([], 52_428_800, [], null, null, ['/srv/CAFÉ'], false);
        $rfm = new \ReflectionMethod($file, 'isPathWithinAllowedRoots');

        $this->assertTrue($rfm->invoke($file, '/srv/café/x.txt', ['/srv/CAFÉ']));
        $this->assertTrue($rfm->invoke($file, '/srv/CAFÉ/x.txt', ['/srv/café']));

        // Folding must not merge genuinely different directories.
        $this->assertFalse($rfm->invoke($file, '/srv/cafe/x.txt', ['/srv/CAFÉ']));
    }

    /**
     * A 4xx is not a redirect. Its body reaches getUrlData() whenever a caller
     * supplies fixed options without CURLOPT_FAILONERROR.
     *
     * @throws \Com\Tecnick\File\Exception
     */
    public function testErrorStatusIsNotTreatedAsARedirect(): void
    {
        $this->requireLocalHttpServer();

        // No fixed options, so FAILONERROR is absent and the 404 body is
        // delivered rather than turned into a transfer failure.
        $file = new \Com\Tecnick\File\File(['127.0.0.1'], 52_428_800, [CURLOPT_FOLLOWLOCATION => false], null, []);

        $this->assertSame(
            'NOTFOUND-BODY',
            $file->getUrlData('http://127.0.0.1:' . self::$serverPort . '/notfound.php'),
        );
    }
}
