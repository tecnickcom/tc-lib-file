<?php

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
     * Cache path (per-instance)
     *
     * @var string
     */
    protected string $path = '';

    /**
     * File prefix (per-instance)
     */
    protected string $prefix;

    /**
     * Set the file prefix (common name)
     *
     * @param string $prefix Common prefix to be used for all cache files
     */
    public function __construct($prefix = null)
    {
        $this->defineSystemCachePath();
        $this->setCachePath();
        if ($prefix === null) {
            $prefix = \rtrim(
                \base64_encode(
                    \pack(
                        'H*',
                        \md5(
                            \uniqid(
                                (string) \random_int(0, \mt_getrandmax()),
                                true
                            ),
                        ),
                    ),
                ),
                '=',
            );
        }

        $this->prefix = '_' . \preg_replace('/[^a-zA-Z0-9_\-]/', '', \strtr($prefix, '+/', '-_')) . '_';
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
        if (($path === null) || (\strpos($path, '://') !== false) || ! \is_writable($path)) {
            /* @phpstan-ignore-next-line */
            $this->path = K_PATH_CACHE;
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
     * @param string $key  File key (used to retrieve file from cache)
     *
     * @return string Temporary filename
     *
     * @throws \Com\Tecnick\File\Exception when a temporary file cannot be created.
     */
    public function getNewFileName(string $type = 'tmp', string $key = '0'): string
    {
        $file = \tempnam($this->path, $this->prefix . $type . '_' . $key . '_');
        if ($file === false) {
            throw new Exception('unable to create a temporary file in: ' . $this->path);
        }

        return $file;
    }

    /**
     * Delete cached files
     *
     * @param ?string $type Type of files to delete
     * @param ?string $key  Specific file key to delete
     */
    public function delete(?string $type = null, ?string $key = null): void
    {
        $safeType = ($type !== null)
            ? \preg_replace('/[^a-zA-Z0-9_\-]/', '', $type)
            : null;
        $safeKey = ($key !== null)
            ? \preg_replace('/[^a-zA-Z0-9_\-]/', '', $key)
            : null;

        $path = $this->path . $this->prefix;
        if ($safeType !== null) {
            $path .= $safeType . '_';
            if ($safeKey !== null) {
                $path .= $safeKey . '_';
            }
        }

        $path .= '*';
        $files = \glob($path);
        if (($files === []) || ($files === false)) {
            return;
        }

        \array_map('unlink', $files);
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
        if (($files === []) || ($files === false)) {
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

        $kPathCache = \ini_get('upload_tmp_dir') ?: \sys_get_temp_dir();
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

        if (! \str_ends_with($rpath, '/')) {
            $rpath .= '/';
        }

        return $rpath;
    }
}
