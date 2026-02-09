<?php

/**
 * Byte.php
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

/**
 * Com\Tecnick\File\Byte
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
 */
class Byte
{
    /**
     * Initialize a new string to be processed
     *
     * @param string $str String from where to extract values
     */
    public function __construct(
        /**
         * String to process
         */
        protected string $str
    ) {
    }

    /**
     * Get BYTE from string (8-bit unsigned integer).
     *
     * @param int $offset Point from where to read the data.
     *
     * @return int 8 bit value
     */
    public function getByte(int $offset): int
    {
        $val = \unpack('Ci', \substr($this->str, $offset, 1));
        return $val === false ? 0 : (\is_int($val['i']) ? $val['i'] : 0);
    }

    /**
     * Get ULONG from string (Big Endian 32-bit unsigned integer).
     *
     * @param int $offset Point from where to read the data
     *
     * @return int 32 bit value
     */
    public function getULong(int $offset): int
    {
        $val = \unpack('Ni', \substr($this->str, $offset, 4));
        return $val === false ? 0 : (\is_int($val['i']) ? $val['i'] : 0);
    }

    /**
     * Get LONG from string (Big Endian 32-bit signed integer).
     *
     * @param int $offset Point from where to read the data
     *
     * @return int 32 bit value
     */
    public function getLong(int $offset): int
    {
        $u_val = $this->getULong($offset);
        // Use bitwise two's complement uint32 to int32 formula
        return ($u_val ^ 0x80000000) - 0x80000000;
    }

    /**
     * Get USHORT from string (Big Endian 16-bit unsigned integer).
     *
     * @param int $offset Point from where to read the data
     *
     * @return int 16 bit value
     */
    public function getUShort(int $offset): int
    {
        $val = \unpack('ni', \substr($this->str, $offset, 2));
        return $val === false ? 0 : (\is_int($val['i']) ? $val['i'] : 0);
    }

    /**
     * Get SHORT from string (Big Endian 16-bit signed integer).
     *
     * @param int $offset Point from where to read the data.
     *
     * @return int 16 bit value
     */
    public function getShort(int $offset): int
    {
        $val = \unpack('ni', \substr($this->str, $offset, 2));
        if ($val === false) {
            return 0;
        }

        if ($val['i'] > 0x7fff) {
            $val['i'] -= 0x10000;
        }

        return \is_int($val['i']) ? $val['i'] : 0;
    }

    /**
     * Get UFWORD from string (Big Endian 16-bit unsigned integer).
     *
     * @param int $offset Point from where to read the data.
     *
     * @return int 16 bit value
     */
    public function getUFWord(int $offset): int
    {
        return $this->getUShort($offset);
    }

    /**
     * Get FWORD from string (Big Endian 16-bit signed integer).
     *
     * @param int $offset Point from where to read the data.
     *
     * @return int 16 bit value
     */
    public function getFWord(int $offset): int
    {
        return $this->getShort($offset);
    }

    /**
     * Get FIXED from string (Big Endian 32-bit signed fixed-point number (16.16)).
     *
     * A fixed-point 16.16 number is 'int16 + uint16/65536.0' where the divisor 65536=(1<<16).
     * A simplified equivalent version is to read an int32 and divide by 65536.
     *
     * @param int $offset Point from where to read the data.
     */
    public function getFixed(int $offset): float
    {
        return $this->getShort($offset) + $this->getUShort($offset + 2) / 65536.0;
    }
}
