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
     *
     * @throws \RangeException on any out-of-bounds read attempt.
     */
    public function __construct(
        /**
         * String to process
         */
        protected string $str
    ) {
    }

    /**
     * Verify that an offset + length read is within the string bounds.
     *
     * @param int $offset Read start position.
     * @param int $length Number of bytes to read.
     *
     * @throws \RangeException if the read exceeds the string length.
     */
    private function checkBounds(int $offset, int $length): void
    {
        if ($offset + $length > \strlen($this->str)) {
            throw new \RangeException(
                'Out-of-bounds read at offset ' . $offset
                . ' (length ' . $length . ', string length ' . \strlen($this->str) . ')'
            );
        }
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
        $this->checkBounds($offset, 1);
        $val = \unpack('Ci', \substr($this->str, $offset, 1));
        return $val === false ? 0 : (\is_int($val['i']) ? ($val['i'] & 0xFF) : 0);
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
        $this->checkBounds($offset, 2);
        $val = \unpack('ni', \substr($this->str, $offset, 2));
        return $val === false ? 0 : (\is_int($val['i']) ? ($val['i'] & 0xFFFF) : 0);
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
        $val = $this->getUShort($offset);
        // convert to signed 16-bit (two's complement)
        return ($val ^ 0x8000) - 0x8000;
        ;
    }

    /**
     * Get UFWORD from string (Big Endian 16-bit unsigned integer).
     * Alias for getUShort().
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
     * Alias for getShort().
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
     * Get ULONG from string (Big Endian 32-bit unsigned integer).
     *
     * @param int $offset Point from where to read the data
     *
     * @return int 32 bit value
     */
    public function getULong(int $offset): int
    {
        $this->checkBounds($offset, 4);
        $val = \unpack('Ni', \substr($this->str, $offset, 4));
        return $val === false ? 0 : (\is_int($val['i']) ? ($val['i'] & 0xFFFFFFFF) : 0);
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
        $val = $this->getULong($offset);
        // convert to signed 32-bit (two's complement)
        return ($val ^ 0x80000000) - 0x80000000;
    }

    /**
     * Get FIXED from string (32-bit signed fixed-point number (16.16).
     *
     * @param int $offset Point from where to read the data.
     */
    public function getFixed(int $offset): float
    {
        return (float) $this->getShort($offset) + ((float) $this->getUShort($offset + 2) / (float) 0x10000);
    }
}
