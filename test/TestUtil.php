<?php

/**
 * TestUtil.php
 *
 * @since     2020-12-19
 * @category  Library
 * @package   file
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 *
 * This file is part of tc-lib-file software library.
 */

namespace Test;

use PHPUnit\Framework\TestCase;

/**
 * Test Util
 *
 * @since     2020-12-19
 * @category  Library
 * @package   file
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
abstract class TestUtil extends TestCase
{
    /**
     * Create a temporary directory and return its canonical path.
     *
     * sys_get_temp_dir() can report a path that realpath() rewrites (an 8.3
     * short name on Windows, the /var -> /private/var symlink on macOS), while
     * the library returns the resolved form.
     *
     * @param string $prefix Prefix of the created directory name.
     *
     * @return string Path of the created directory, without a trailing separator.
     */
    protected static function makeTempDir(string $prefix = 'tclf_'): string
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . $prefix . \uniqid('', true);
        if (!\mkdir($dir, 0o777, true)) {
            self::fail('unable to create the temporary directory: ' . $dir);
        }

        $real = \realpath($dir);
        if ($real === false) {
            self::fail('unable to resolve the temporary directory: ' . $dir);
        }

        return $real;
    }
}
