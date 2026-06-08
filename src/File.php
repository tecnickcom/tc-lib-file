<?php

declare(strict_types=1);

/**
 * File.php
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

namespace Com\Tecnick\File;

use Com\Tecnick\File\Exception as FileException;

/**
 * Com\Tecnick\File\File
 *
 * Function to read byte-level data
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-file
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class File
{
    /**
     * Array of default cURL options for curl_setopt_array.
     *
     * @var array<int, bool|int|string> cURL options.
     */
    protected const CURLOPT_DEFAULT = [
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'tc-lib-file',
    ];

    /**
     * Array of fixed cURL options for curl_setopt_array.
     *
     * @var array<int, bool|int|string> cURL options.
     */
    protected const CURLOPT_FIXED = [
        CURLOPT_FAILONERROR => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    /**
     * Custom cURL options for curl_setopt_array.
     *
     * @var array<int, bool|int|string> cURL options.
     */
    protected array $curlopts = [];

    /**
     * Default cURL options (instance-level, initialized from CURLOPT_DEFAULT constant).
     * Can be customized via constructor parameter.
     *
     * @var array<int, bool|int|string> cURL options.
     */
    protected array $defaultCurlOpts;

    /**
     * Fixed cURL options that are always applied (instance-level, initialized from CURLOPT_FIXED constant).
     * Can be customized via constructor parameter.
     * These are applied last to ensure security-critical settings cannot be overridden.
     *
     * @var array<int, bool|int|string> cURL options.
     */
    protected array $fixedCurlOpts;

    /**
     * Allowlist of trusted HTTP_HOST values for use in alt-path helpers.
     * An empty array (the default) means no host is trusted, so HTTP_HOST-based
     * URL construction is skipped entirely. Set to a non-empty list of exact
     * hostname strings to enable the feature for specific hosts.
     *
     * SECURITY WARNING: using '*' trusts any host value and disables host
     * validation. If request metadata (for example HTTP_HOST / SCRIPT_URI) is
     * attacker-controlled via reverse-proxy misconfiguration or header
     * injection, wildcard trust can enable SSRF/open-redirect style behavior by
     * letting untrusted hosts influence alternate URL/path resolution.
     * Prefer explicit trusted hostnames whenever possible.
     *
     * @var array<string>
     */
    protected array $allowedHosts = [];

    /**
     * Allowlist of trusted file paths for use in local alt-path helpers.
     * An empty array (the default) means no file path is trusted for
     * allowlist-based path resolution.
     *
     * SECURITY WARNING: using '*' trusts any file path value and disables
     * path validation. If any path input can be influenced by untrusted data,
     * wildcard trust can enable local file inclusion/path traversal style
     * behavior by allowing access to unintended files.
     * Prefer explicit trusted paths whenever possible.
     *
     * @var array<string>
     */
    protected array $allowedPaths = [];

    /**
     * Maximum size (in bytes) for remote file reads via HTTP(S) or FTP.
     * Reads exceeding this limit will throw an exception.
     * Default is 52428800 bytes (50 MB).
     *
     * @var int
     */
    protected int $maxRemoteSize = 52_428_800;

    /**
     * Initialize the File object.
     *
     * @param array<string>                    $allowedHosts    Allowlist of trusted hostnames.
     *                                                          Defaults to an empty array (no host trusted).
     * @param int                              $maxRemoteSize   Maximum size in bytes for remote file reads.
     *                                                          Defaults to 52428800 (50 MB).
     * @param array<int, bool|int|string>      $curlopts        Custom cURL options to merge over defaults.
     * @param array<int, bool|int|string>|null $defaultCurlOpts Optional override for default cURL options.
     *                                                          If not provided, CURLOPT_DEFAULT is used.
     * @param array<int, bool|int|string>|null $fixedCurlOpts   Optional override for fixed cURL options.
     *                                                          If not provided, CURLOPT_FIXED is used.
     * @param array<string>                    $allowedPaths    Allowlist of trusted file paths.
     *                                                          Defaults to an empty array (no internal path trusted).
     */
    public function __construct(
        array $allowedHosts = [],
        int $maxRemoteSize = 52_428_800,
        array $curlopts = [],
        ?array $defaultCurlOpts = null,
        ?array $fixedCurlOpts = null,
        array $allowedPaths = [],
    ) {
        $this->allowedHosts = $allowedHosts;
        $this->maxRemoteSize = $maxRemoteSize;
        $this->curlopts = $curlopts;
        $this->defaultCurlOpts = $defaultCurlOpts ?? self::CURLOPT_DEFAULT;
        $this->fixedCurlOpts = $fixedCurlOpts ?? self::CURLOPT_FIXED;
        $this->allowedPaths = $allowedPaths;
    }

    /**
     * Set custom cURL options.
     *
     * @param array<int, bool|int|string> $curlopts Custom cURL options to merge over defaults.
     */
    public function setCurlOpts(array $curlopts): static
    {
        $this->curlopts = $curlopts;
        return $this;
    }

    /**
     * Set the allowlist of trusted hostnames.
     *
     * @param array<string> $allowedHosts Trusted hostname strings.
     */
    public function setAllowedHosts(array $allowedHosts): static
    {
        $this->allowedHosts = $allowedHosts;
        return $this;
    }

    /**
     * Set the allowlist of trusted file paths.
     *
     * @param array<string> $allowedPaths Trusted file path strings.
     */
    public function setAllowedPaths(array $allowedPaths): static
    {
        $this->allowedPaths = $allowedPaths;
        return $this;
    }

    /**
     * Set the maximum size (in bytes) for remote file reads.
     *
     * @param int $maxRemoteSize Maximum allowed bytes.
     */
    public function setMaxRemoteSize(int $maxRemoteSize): static
    {
        $this->maxRemoteSize = $maxRemoteSize;
        return $this;
    }

    /**
     * Get the maximum size (in bytes) for remote file reads.
     */
    public function getMaxRemoteSize(): int
    {
        return $this->maxRemoteSize;
    }

    /**
     * Wrapper to use fopen only with local files.
     *
     * @param string $file Name of the file to open.
     * @param string $mode The fopen mode parameter specifies the type of access you require to the stream.
     *
     * @return resource Returns a file pointer resource on success.
     *
     * @throws FileException in case of error.
     */
    public function fopenLocal(string $file, string $mode): mixed
    {
        if (!$this->isValidFile($file)) {
            throw new FileException('invalid file');
        }

        $handler = $this->withoutPhpWarnings(static fn() => \fopen($file, $mode));
        if ($handler === false) {
            throw new FileException('unable to open the file: ' . $file);
        }

        return $handler;
    }

    /**
     * Read a 4-byte (32 bit) integer from file.
     *
     * @param resource $resource A file system pointer resource that is typically created using \fopen().
     *
     * @return int 4-byte integer.
     *
     * @throws FileException in case of error.
     */
    public function fReadInt(mixed $resource): int
    {
        $data = $this->withoutPhpWarnings(static fn() => \fread($resource, 4));
        if ($data === false) {
            throw new FileException('unable to read the file');
        }

        $val = \unpack('Ni', $data);
        $read = $val !== false ? $val['i'] ?? null : null;
        return \is_int($read) ? $read : 0;
    }

    /**
     * Binary-safe file read.
     * Reads up to length bytes from the file pointer referenced by handle.
     * Reading stops as soon as one of the following conditions is met:
     * length bytes have been read; EOF (end of file) is reached.
     *
     * @param ?resource  $resource A file system pointer resource that is typically created using \fopen().
     * @param int<1, max> $length  Number of bytes to read.
     *
     * @throws FileException in case of error
     */
    public function rfRead(mixed $resource, int $length): string
    {
        if (!\is_resource($resource)) {
            throw new FileException('unable to read the file');
        }

        $data = '';
        while (\strlen($data) < $length && !\feof($resource)) {
            $remaining = \max(1, $length - \strlen($data));
            $chunk = \fread($resource, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        if ($data === '') {
            throw new FileException('unable to read the file');
        }

        return $data;
    }

    /**
     * Check whether the stream still has buffered unread bytes.
     *
     * @param resource $resource A file system pointer resource.
     */
    protected function hasUnreadBytes(mixed $resource): bool
    {
        $stream_meta_data = \stream_get_meta_data($resource);
        return $stream_meta_data['unread_bytes'] > 0;
    }

    /**
     * Reads entire file into a string.
     * The file can be also an URL.
     *
     * @param string $file Name of the file or URL to read.
     *
     * @throws FileException in case of error.
     */
    public function fileGetContents(string $file): string
    {
        $alt = $this->getAltFilePaths($file);
        foreach ($alt as $path) {
            $ret = $this->getFileData($path);
            if ($ret !== false) {
                return $ret;
            }
        }

        throw new FileException('unable to read the file: ' . $file);
    }

    /**
     * Reads entire file into a string.
     * The file can be also an URL.
     *
     * @param string $file Name of the file or URL to read.
     *
     * @return string|false File content or FALSE in case the file is unreadable
     *
     * @throws FileException in case the remote transfer is aborted due to max size.
     */
    public function getFileData(string $file): string|false
    {
        $data = $this->getLocalFileData($file);

        if ($data === false) {
            return $this->getUrlData($file);
        }

        return $data;
    }

    /**
     * Reads entire local file into a string.
     *
     * @param string $file Name of the file to read.
     *
     * @return string|false File content or FALSE in case the file is unreadable
     *
     * @throws FileException in case the remote transfer is aborted due to max size.
     */
    public function getLocalFileData(string $file): string|false
    {
        if (!$this->isValidFile($file)) {
            return false;
        }

        return $this->withoutPhpWarnings(static fn() => \file_get_contents($file));
    }

    /**
     * Execute a callable while suppressing expected PHP warnings/notices.
     *
     * These low-level filesystem calls already signal failure via their return
     * values, and the public methods convert that into a FileException.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    private function withoutPhpWarnings(callable $callback): mixed
    {
        \set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);

        try {
            return $callback();
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Progress callback factory for curl to enforce max remote file size.
     * Returns a callable that enforces the size limit during transfer.
     *
     * @param int $bytesRead Reference to track bytes downloaded
     *
     * @return callable Progress callback for CURLOPT_PROGRESSFUNCTION
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    private function createProgressCallback(int &$bytesRead): callable
    {
        $maxSize = $this->maxRemoteSize;
        return static function ($_curlResource, $_downloadSize, $downloaded, $_uploadSize, $_uploaded) use (
            &$bytesRead,
            $maxSize,
        ) {
            // @phpstan-ignore-next-line
            $bytesRead = (int) $downloaded;
            if ($bytesRead > $maxSize) {
                // Returning non-zero aborts the transfer
                return 1;
            }
            return 0;
        };
    }

    /**
     * Build an absolute URL from a redirect Location header value.
     *
     * Supports absolute, scheme-relative, root-relative and relative
     * redirect targets.
     *
     * @param string $location Redirect target from Location header.
     * @param string $baseUrl  Effective URL of the current response.
     *
     * @return string|false Absolute HTTP(S) URL or false when invalid.
     */
    private function buildRedirectUrl(string $location, string $baseUrl): string|false
    {
        $location = \trim($location);
        if ($location === '') {
            return false;
        }

        if (\preg_match('%^https?://%i', $location) === 1) {
            return $location;
        }

        $base = \parse_url($baseUrl);
        if (!\is_array($base)) {
            return false;
        }

        $scheme = $base['scheme'] ?? null;
        $host = $base['host'] ?? null;
        if (!\is_string($scheme) || !\is_string($host) || $scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $authority = $scheme . '://' . $host;
        $port = $base['port'] ?? null;
        if (\is_int($port)) {
            $authority .= ':' . $port;
        }

        if (\str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if ($location[0] === '/') {
            return $authority . $location;
        }

        $basePath = $base['path'] ?? '/';

        $dir = (string) \preg_replace('%/[^/]*$%', '/', $basePath);
        if ($dir === '') {
            $dir = '/';
        }

        return $authority . $dir . $location;
    }

    /**
     * Build a cURL header callback that validates each redirect target URL.
     *
     * The callback aborts the transfer when a Location header resolves to an
     * invalid or non-allowlisted URL.
     *
     * @param bool   $invalidRedirect Flag set to true when a redirect is invalid.
     * @param string $initialUrl      Initial request URL.
     *
     * @return callable Header callback for CURLOPT_HEADERFUNCTION.
     */
    private function createRedirectValidationCallback(bool &$invalidRedirect, string $initialUrl): callable
    {
        return function ($curlResource, string $headerLine) use (&$invalidRedirect, $initialUrl): int {
            if (\stripos($headerLine, 'Location:') !== 0) {
                return \strlen($headerLine);
            }

            $location = \trim(\substr($headerLine, 9));
            if ($location === '') {
                $invalidRedirect = true;
                return 0;
            }

            if (!$curlResource instanceof \CurlHandle) {
                $invalidRedirect = true;
                return 0;
            }

            $effectiveUrl = (string) \curl_getinfo($curlResource, CURLINFO_EFFECTIVE_URL);
            $baseUrl = $effectiveUrl !== '' ? $effectiveUrl : $initialUrl;

            $redirectUrl = $this->buildRedirectUrl($location, $baseUrl);
            if ($redirectUrl === false || !$this->isValidURL($redirectUrl)) {
                $invalidRedirect = true;
                return 0;
            }

            return \strlen($headerLine);
        };
    }

    /**
     * Reads entire remote file into a string using CURL
     *
     * @param string $url URL to read.
     *
     * @throws FileException if the remote transfer is aborted due to max size.
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    public function getUrlData(string $url): string|false
    {
        if (!$this->isValidURL($url)) {
            return false;
        }

        if (
            \ini_get('allow_url_fopen') && !\defined('FORCE_CURL')
            || !\function_exists('curl_init')
            || \preg_match('%^https?://%', $url) === 0
            || \preg_match('%^https?://%', $url) === false
        ) {
            return false;
        }

        // try to get remote file data using cURL
        $curlHandle = \curl_init();
        if ($curlHandle === false) {
            return false;
        }

        $curlopts = [];

        $openBasedir = \ini_get('open_basedir');
        if ($openBasedir === false || $openBasedir === '') {
            $curlopts[CURLOPT_FOLLOWLOCATION] = true;
        }

        $curlopts = \array_replace($curlopts, $this->defaultCurlOpts);
        $curlopts = \array_replace($curlopts, $this->curlopts);
        $curlopts = \array_replace($curlopts, $this->fixedCurlOpts);
        $curlopts[CURLOPT_URL] = $url;

        // Use a progress callback to enforce the max remote size limit
        $bytesRead = 0;
        $curlopts[CURLOPT_NOPROGRESS] = false;
        $curlopts[CURLOPT_PROGRESSFUNCTION] = $this->createProgressCallback($bytesRead);

        $invalidRedirect = false;
        $maxRedirects = (int) ($curlopts[CURLOPT_MAXREDIRS] ?? 0);
        if ($maxRedirects !== 0) {
            $curlopts[CURLOPT_HEADERFUNCTION] = $this->createRedirectValidationCallback($invalidRedirect, $url);
        }

        \curl_setopt_array($curlHandle, $curlopts);

        try {
            $ret = \curl_exec($curlHandle);

            if ($invalidRedirect) {
                return false;
            }

            // Check if transfer was aborted due to size limit
            $curlError = \curl_errno($curlHandle);
            if ($curlError === 42) { // CURLE_ABORTED_BY_CALLBACK
                throw new FileException(
                    'remote file exceeds maximum allowed size of ' . $this->maxRemoteSize . ' bytes',
                );
            }

            if ($ret === false) {
                return false;
            }

            return $ret === true ? '' : $ret;
        } finally {
            // Let PHP close the cURL handle automatically at scope end.
        }
    }

    /**
     * Returns an array of possible alternative file paths or URLs
     *
     * @param string $file Name of the file or URL to read.
     *
     * @return array<string> List of possible alternative file paths or URLs.
     */
    public function getAltFilePaths(string $file): array
    {
        $alt = [$file];
        $alt[] = $this->getAltLocalUrlPath($file);
        $url = $this->getAltMissingUrlProtocol($file);
        $alt[] = $url;
        $alt[] = $this->getAltPathFromUrl($url);
        $alt[] = $this->getAltUrlFromPath($file);
        return \array_unique($alt);
    }

    /**
     * Resolve a local file path against explicit base directories.
     *
     * This helper does not validate trust boundaries and does not perform any
     * file read. It only turns an existing local relative path into an absolute
     * canonical path when one of the provided base directories matches.
     *
     * @param string        $file     Local file path to resolve.
     * @param array<string> $baseDirs Candidate base directories checked in order.
     */
    public function resolveLocalPath(string $file, array $baseDirs = []): string
    {
        $file = \trim($file);
        if ($file === '' || $this->hasDoubleDots($file) || \str_contains($file, '://')) {
            return $file;
        }

        $resolved = \realpath($file);
        if (\is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        foreach ($baseDirs as $baseDir) {
            if ($baseDir === '') {
                continue;
            }

            $resolvedBase = \realpath($baseDir);
            if (!\is_string($resolvedBase) || $resolvedBase === '') {
                continue;
            }

            $resolved = \realpath($resolvedBase . \DIRECTORY_SEPARATOR . $file);
            if (\is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return $file;
    }

    /**
     * Replace URL relative path with full real server path
     *
     * @param string $file Relative URL path
     */
    protected function getAltLocalUrlPath(string $file): string
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (
            \strlen($file) > 1
            && $file[0] === '/'
            && $file[1] !== '/'
            && \is_string($documentRoot)
            && $documentRoot !== '/'
        ) {
            $findroot = \strpos($file, $documentRoot);
            if ($findroot === false || $findroot > 1) {
                $file = \htmlspecialchars_decode(\urldecode($documentRoot . $file));
            }
        }

        return $file;
    }

    /**
     * Add missing local URL protocol.
     *
     * @param string $file Relative URL path
     *
     * @return string local path or original $file
     */
    protected function getAltMissingUrlProtocol(string $file): string
    {
        $httpHost = $_SERVER['HTTP_HOST'] ?? null;
        if (\preg_match('%^//%', $file) && \is_string($httpHost) && $this->isValidHost($httpHost)) {
            $file = $this->getDefaultUrlProtocol() . ':' . \str_replace(' ', '%20', $file);
        }

        return \htmlspecialchars_decode($file);
    }

    /**
     * Get the default URL protocol (http or https).
     */
    protected function getDefaultUrlProtocol(): string
    {
        $protocol = 'http';
        $https = $_SERVER['HTTPS'] ?? null;
        if (\is_string($https) && $https !== '' && \strtolower($https) !== 'off') {
            $protocol .= 's';
        }

        return $protocol;
    }

    /**
     * Add missing local URL protocol.
     *
     * @param string $url Relative URL path
     *
     * @return string local path or original $file
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    protected function getAltPathFromUrl(string $url): string
    {
        $httpHost = $_SERVER['HTTP_HOST'] ?? null;
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;

        if (
            \preg_match('%^(https?)://%', $url) !== 1
            || !\is_string($httpHost)
            || !$this->isValidHost($httpHost)
            || !\is_string($documentRoot)
        ) {
            return $url;
        }

        $urldata = \parse_url($url);
        if (\is_array($urldata) && \array_key_exists('query', $urldata)) {
            return $url;
        }

        $host = $this->getDefaultUrlProtocol() . '://' . $httpHost;
        if (\str_starts_with($url, $host)) {
            // convert URL to full server path
            $tmp = \str_replace($host, $documentRoot, $url);
            return \htmlspecialchars_decode(\urldecode($tmp));
        }

        return $url;
    }

    /**
     * Get an alternate URL from a file path.
     *
     * @param string $file File name and path
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    protected function getAltUrlFromPath(string $file): string
    {
        $scriptUri = $_SERVER['SCRIPT_URI'] ?? null;
        if (
            \is_string($scriptUri)
            && $scriptUri !== ''
            && \preg_match('%^(https?)://%', $file) !== 1
            && \preg_match('%^//%', $file) !== 1
        ) {
            $urldata = \parse_url($scriptUri);
            if (
                !\is_array($urldata)
                || !\array_key_exists('scheme', $urldata)
                || !\array_key_exists('host', $urldata)
            ) {
                return $file;
            }

            // Validate SCRIPT_URI host against allowlist to prevent SSRF attacks.
            // If the host is not trusted, return the original file path unchanged.
            if (!$this->isValidHost($urldata['host'])) {
                return $file;
            }

            return $urldata['scheme'] . '://' . $urldata['host'] . ($file[0] === '/' ? '' : '/') . $file;
        }

        return $file;
    }

    /**
     * Validate an HTTP(S) URL against the configured host allowlist.
     *
     * Returns true only when the URL parses correctly, uses the http or
     * https scheme, and has a non-empty host trusted by isValidHost().
     * Returns false for invalid URLs, unsupported schemes, missing hosts,
     * or untrusted hosts.
     *
     * @param string $url URL to validate.
     */
    public function isValidURL(string &$url): bool
    {
        $url = \trim($url);
        if ($url === '') {
            return false;
        }

        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }

        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (!\is_string($host)) {
            return false;
        }

        return $this->isValidHost($host);
    }

    /**
     * Validate that the given hostname appears in the $allowedHosts allowlist.
     * Returns true when the hostname is trusted, false otherwise.
     * When the allowlist is empty (the default) every host is rejected.
     *
     * @param string $host Hostname to validate (e.g. value of $_SERVER['HTTP_HOST']).
     */
    protected function isValidHost(string $host): bool
    {
        return (
            $host !== ''
            && (\in_array('*', $this->allowedHosts, true) || \in_array($host, $this->allowedHosts, true))
        );
    }

    /**
     * Check whether a path is inside at least one allowed root.
     *
     * A plain prefix is not sufficient: '/var/www_evil' must not match
     * '/var/www'. This helper requires exact root match or a root plus
     * directory separator boundary.
     *
     * @param string        $path  Path to validate.
     * @param array<string> $roots Allowed path prefixes.
     */
    protected function isPathWithinAllowedRoots(string $path, array $roots): bool
    {
        foreach ($roots as $allowedPath) {
            if ($allowedPath === '') {
                continue;
            }

            $root = \rtrim($allowedPath, '/\\');
            if ($root === '') {
                continue;
            }

            if ($path === $root || \str_starts_with($path, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a local file path against the configured $allowedPaths allowlist.
     *
     * Returns true when:
     * - wildcard trust ('*') is enabled, or
     * - the normalized local path starts with one trusted allowlist prefix.
     *
     * Returns false for parent-directory traversal patterns ('..'),
     * non-file schemes, and when no allowlist entry matches.
     * When the allowlist is empty (default), every path is rejected.
     *
     * The 'file://' schema is added to the input $file parameter if missing.
     *
     * @param string $file File path to validate.
     */
    public function isValidFile(string &$file): bool
    {
        $file = \trim($file);
        if ($file === '' || $this->hasDoubleDots($file)) {
            return false;
        }

        if (!\str_contains($file, '://')) {
            $file = 'file://' . $file;
        }

        if (!\str_starts_with($file, 'file://')) {
            return false;
        }

        // remove 'file://' schema
        $filepath = \trim(\substr($file, 7));

        if ($filepath === '') {
            return false;
        }

        if (\in_array('*', $this->allowedPaths, true)) {
            return true;
        }

        if (!$this->isPathWithinAllowedRoots($filepath, $this->allowedPaths)) {
            return false;
        }

        // Canonical-path check blocks symlink escapes from trusted roots.
        // For non-existing targets, walk up to the nearest existing ancestor
        // and validate its canonical path.
        $realPathToCheck = $filepath;
        while (\realpath($realPathToCheck) === false) {
            $parentPath = \dirname($realPathToCheck);
            if ($parentPath === $realPathToCheck || $parentPath === '.') {
                return false;
            }

            $realPathToCheck = $parentPath;
        }

        $realPath = \realpath($realPathToCheck);
        if ($realPath === false || !$this->isPathWithinAllowedRoots($realPath, $this->allowedPaths)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the path contains parent directory dots ('..').
     *
     * @param string $path path to check
     *
     * @return boolean true if the path is relative
     */
    protected function hasDoubleDots(string $path): bool
    {
        return \str_contains(\str_ireplace('%2E', '.', \html_entity_decode($path, ENT_QUOTES, 'UTF-8')), '..');
    }
}
