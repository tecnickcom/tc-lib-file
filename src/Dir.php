<?php
/**
 * Dir.php
 *
 * @since       2015-07-28
 * @category    Library
 * @package     File
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2015-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnick.com/tc-lib-file
 *
 * This file is part of tc-lib-file software library.
 */

namespace Com\Tecnick\File;

use \Com\Tecnick\File\Exception as FileException;

/**
 * Com\Tecnick\File\Dir
 *
 * Function to read byte-level data
 *
 * @since       2015-07-28
 * @category    Library
 * @package     File
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2015-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnick.com/tc-lib-file
 */
class Dir
{
    /**
     * Returns the full path of a parent directory
     *
     * @param string $name Name of the parent folder to search
     * @param string $dir  Starting directory
     *
     * @return string Directory name
     */
    public function findParentDir($name, $dir = __DIR__)
    {
        while (!empty($dir)) {
            if ($dir == '/') {
                $dir = '';
            }
            if (@is_writable($dir.'/'.$name)) {
                $dir = $dir.'/'.$name;
                break;
            }
            $dir = dirname($dir);
        }
        if (substr($dir, -1) !== '/') {
            $dir .= '/';
        }
        return $dir;
    }
}
