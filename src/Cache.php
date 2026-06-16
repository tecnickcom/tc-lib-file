<?php

declare(strict_types=1);

/**
 * Cache.php
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

namespace Com\Tecnick\File;

use Com\Tecnick\File\Exception as FileException;
use Random\RandomException;

/**
 * Com\Tecnick\Pdf\File\Cache
 *
 * File caching system with per-instance path and prefix.
 * Each Cache instance maintains its own cache directory path and file prefix.
 *
 * @since     2011-05-23
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-filecache
 */
class Cache
{
    /**
     * Whether this is a Windows machine
     */
    protected bool $isWindows;

    /**
     * Cache path (per-instance)
     */
    protected string $path = '';

    /**
     * File prefix (per-instance)
     */
    protected string $prefix;

    /**
     * Set the file prefix (common name)
     *
     * @param ?string $prefix Common prefix to be used for all cache files
     *
     * @throws FileException
     * @throws RandomException
     */
    public function __construct(?string $prefix = null)
    {
        $this->isWindows = \PHP_OS_FAMILY === 'Windows';

        $this->defineSystemCachePath();
        $this->setCachePath();
        $prefix ??= \rtrim(
            \base64_encode(\pack('H*', \md5(\uniqid((string) \random_int(0, \PHP_INT_MAX), true)))),
            '=',
        );

        $safePrefix = \preg_replace('/[^a-zA-Z0-9_\-]/', '', \strtr($prefix, '+/', '-_')) ?? '';
        $this->prefix = '_' . $safePrefix . '_';
    }

    /**
     * Get the cache directory path
     */
    public function getCachePath(): string
    {
        return $this->path;
    }

    /**
     * Set the default cache directory path
     *
     * @param ?string $path Cache directory path; if null use the K_PATH_CACHE value
     */
    public function setCachePath(?string $path = null): void
    {
        if ($path === null || \str_contains($path, '://') || !\is_writable($path)) {
            $this->path = (string) \constant('K_PATH_CACHE');
            return;
        }

        $this->path = $this->normalizePath($path);
    }

    /**
     * Get the file prefix
     */
    public function getFilePrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Returns a temporary filename for caching files.
     * Throws an exception when tempnam() fails, consistent with the rest of the library.
     *
     * @param string $type Type of file
     * @param string $key File key (used to retrieve file from cache)
     *
     * @return string Temporary filename
     *
     * @throws FileException
     * @throws RandomException
     */
    public function getNewFileName(string $type = 'tmp', string $key = '0'): string
    {
        if (!$this->isWindows) {
            $file = \tempnam($this->path, $this->prefix . $type . '_' . $key . '_');
            if ($file === false) {
                throw new FileException('unable to create a temporary file in: ' . $this->path);
            }
            return $file;
        }

        // Windows: tempnam() truncates the prefix to 3 chars, collapsing the
        // type/key keying. Reimplement with atomic create-exclusive instead.
        return $this->createWindowsTempFile($type, $key);
    }

    /**
     * Returns a Windows specific temporary filename for caching files.
     * Throws an exception when unable to create a temporary file,
     * consistent with the rest of the library.
     *
     * @param string $type Type of file
     * @param string $key File key (used to retrieve file from cache)
     *
     * @return string Temporary filename
     *
     * @throws FileException
     * @throws RandomException
     */
    private function createWindowsTempFile(string $type = 'tmp', string $key = '0'): string
    {
        // Windows MAX_PATH is 260 incl. the NUL terminator, so the usable path
        // length is 259. We budget for the full final name: $base + hex + ".tmp"
        // This assumes non-long-path-aware PHP, which is the stock CLI default.
        $maxPathLength = 259;
        // suffix = '.tmp'
        $suffixLength = 4;
        // at least 1 random byte (2 hex characters)
        $minHexLength = 2;

        $base = $this->path . $this->prefix . "{$type}_{$key}_";

        // Room left for the random hex segment after base + ".tmp".
        $entropyBudget = $maxPathLength - \strlen($base) - $suffixLength;

        if ($entropyBudget < $minHexLength) {
            throw new FileException("Cache filepath exceeds maximum length of {$maxPathLength} on Windows.");
        }

        // Cap at 15 bytes (30 hex chars); use as much of the remaining budget as fits.
        $numBytes = \max(1, \min(15, \intdiv($entropyBudget, 2)));

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $filepath = $base . \bin2hex(\random_bytes($numBytes)) . '.tmp';
            $handle = @\fopen($filepath, 'x');
            if ($handle !== false) {
                \fclose($handle);
                return $filepath;
            }
        }

        throw new FileException("Unable to create a temporary file in: {$this->path}");
    }
    /**
     * Delete cached files
     *
     * @param ?string $type Type of files to delete
     * @param ?string $key Specific file key to delete
     */
    public function delete(?string $type = null, ?string $key = null): void
    {
        $safeType = $type !== null ? \preg_replace('/[^a-zA-Z0-9_\-]/', '', $type) : null;
        $safeKey = $key !== null ? \preg_replace('/[^a-zA-Z0-9_\-]/', '', $key) : null;

        $path = $this->path . $this->prefix;
        if ($safeType !== null) {
            $path .= $safeType . '_';
            if ($safeKey !== null) {
                $path .= $safeKey . '_';
            }
        }

        $files = \glob($path . '*');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            \unlink($file);
        }
    }

    /**
     * Delete cache files older than the given number of seconds.
     *
     * @param int $seconds Maximum age in seconds; files whose mtime is older are removed.
     */
    public function deleteOlderThan(int $seconds): void
    {
        $pattern = $this->path . $this->prefix . '*';
        $files = \glob($pattern);
        if ($files === [] || $files === false) {
            return;
        }

        $cutoff = \time() - $seconds;
        foreach ($files as $file) {
            $mtime = \filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                \unlink($file);
            }
        }
    }

    /**
     * Set the K_PATH_CACHE constant (if not set) to the default system directory for temporary files
     */
    protected function defineSystemCachePath(): void
    {
        if (\defined('K_PATH_CACHE')) {
            return;
        }

        $kPathCache = \ini_get('upload_tmp_dir');
        if (!$kPathCache) {
            $kPathCache = \sys_get_temp_dir();
        }
        \define('K_PATH_CACHE', $this->normalizePath($kPathCache));
    }

    /**
     * Normalize cache path
     *
     * @param string $path Path to normalize
     */
    protected function normalizePath(string $path): string
    {
        $rpath = \realpath($path);
        if ($rpath === false) {
            return '';
        }

        if (!\str_ends_with($rpath, '/')) {
            $rpath .= '/';
        }

        return $rpath;
    }
}
