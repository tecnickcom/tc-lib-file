<?php

/**
 * ByteTest.php
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
 * Byte Color class test
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
class ByteTest extends TestUtil
{
    protected function getTestObject(): \Com\Tecnick\File\Byte
    {
        $str = \chr(0) . \chr(0) . \chr(0) . \chr(0)
            . \chr(1) . \chr(3) . \chr(7) . \chr(15)
            . \chr(31) . \chr(63) . \chr(127) . \chr(255)
            . \chr(254) . \chr(252) . \chr(248) . \chr(240)
            . \chr(224) . \chr(192) . \chr(128) . \chr(0)
            . \chr(255) . \chr(255) . \chr(255) . \chr(255);
        return new \Com\Tecnick\File\Byte($str);
    }

    #[DataProvider('getByteDataProvider')]
    public function testGetByte(int $offset, int $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getByte($offset);
        $this->assertEquals($expected, $res);
    }

    /**
     * @return array<array{int, int}>
     */
    public static function getByteDataProvider(): array
    {
        return [
            [0, 0],
            [1, 0],
            [2, 0],
            [3, 0],
            [4, 1],
            [5, 3],
            [6, 7],
            [7, 15],
            [8, 31],
            [9, 63],
            [10, 127],
            [11, 255],
            [12, 254],
            [13, 252],
            [14, 248],
            [15, 240],
            [16, 224],
            [17, 192],
            [18, 128],
            [19, 0],
            [20, 255],
            [21, 255],
            [22, 255],
            [23, 255],
        ];
    }

    #[DataProvider('getULongDataProvider')]
    public function testGetULong(int $offset, int $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getULong($offset);
        $this->assertEquals($expected, $res);
    }

    /**
     * @return array<array{int, int}>
     */
    public static function getULongDataProvider(): array
    {
        return [
            [0, 0],
            [1, 1],
            [2, 259],
            [3, 66311],
            [4, 16_975_631],
            [5, 50_794_271],
            [6, 118_431_551],
            [7, 253_706_111],
            [8, 524_255_231],
            [9, 1_065_353_214],
            [10, 2_147_483_388],
            [11, 4_294_900_984],
            [12, 4_277_991_664],
            [13, 4_244_173_024],
            [14, 4_176_535_744],
            [15, 4_041_261_184],
            [16, 3_770_712_064],
            [17, 3_229_614_335],
            [18, 2_147_549_183],
            [19, 16_777_215],
            [20, 4_294_967_295],
        ];
    }

    #[DataProvider('getLongDataProvider')]
    public function testGetLong(int $offset, int $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getLong($offset);
        $this->assertEquals($expected, $res);
    }

    /**
     * @return array<array{int, int}>
     */
    public static function getLongDataProvider(): array
    {
        return [
            [0, 0],
            [1, 1],
            [2, 259],
            [3, 66_311],
            [4, 16_975_631],
            [5, 50_794_271],
            [6, 118_431_551],
            [7, 253_706_111],
            [8, 524_255_231],
            [9, 1_065_353_214],
            [10, 2_147_483_388],
            [11, -66_312],
            [12, -16_975_632],
            [13, -50_794_272],
            [14, -118_431_552],
            [15, -253_706_112],
            [16, -524_255_232],
            [17, -1_065_352_961],
            [18, -2_147_418_113],
            [19, 16_777_215],
            [20, -1],
        ];
    }

    #[DataProvider('getUShortDataProvider')]
    public function testGetUShort(int $offset, int $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getUShort($offset);
        $this->assertEquals($expected, $res);
    }

    #[DataProvider('getUShortDataProvider')]
    public function testGetUFWord(int $offset, int $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getUFWord($offset);
        $this->assertEquals($expected, $res);
    }

    /**
     * @return array<array{int, int}>
     */
    public static function getUShortDataProvider(): array
    {
        return [
            [0, 0],
            [1, 0],
            [2, 0],
            [3, 1],
            [4, 259],
            [5, 775],
            [6, 1807],
            [7, 3871],
            [8, 7999],
            [9, 16255],
            [10, 32767],
            [11, 65534],
            [12, 65276],
            [13, 64760],
            [14, 63728],
            [15, 61664],
            [16, 57536],
            [17, 49280],
            [18, 32768],
            [19, 255],
            [20, 65535],
            [21, 65535],
            [22, 65535],
        ];
    }

    #[DataProvider('getShortDataProvider')]
    public function testGetShort(int $offset, int $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getShort($offset);
        $this->assertEquals($expected, $res);
    }

    #[DataProvider('getShortDataProvider')]
    public function testGetFWord(int $offset, int $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getFWord($offset);
        $this->assertEquals($expected, $res);
    }

    /**
     * @return array<array{int, int}>
     */
    public static function getShortDataProvider(): array
    {
        return [
            [0, 0],
            [1, 0],
            [2, 0],
            [3, 1],
            [4, 259],
            [5, 775],
            [6, 1807],
            [7, 3871],
            [8, 7999],
            [9, 16255],
            [10, 32767],
            [11, -2],
            [12, -260],
            [13, -776],
            [14, -1808],
            [15, -3872],
            [16, -8000],
            [17, -16256],
            [18, -32768],
            [19, 255],
            [20, -1],
            [21, -1],
            [22, -1],
        ];
    }

    #[DataProvider('getFixedDataProvider')]
    public function testGetFixed(int $offset, int|float $expected): void
    {
        $byte = $this->getTestObject();

        // Test the getFixed method
        $res = $byte->getFixed($offset);
        // Also test an alternate algorithm of reading all 4 bytes as an int32 and dividing by 65536.0
        $res2 = $byte->getLong($offset) / 65536.0;

        // 16-bit floating point (IEEE 754 half-precision) has an epsilon (epsilon) of 2^-10,
        // which is approximately 0.00097656.
        $delta = pow(2, -10);
        $this->assertEqualsWithDelta($expected, $res, $delta);
        $this->assertEqualsWithDelta($expected, $res2, $delta);
    }

    /**
     * @return array<array{int, float}>
     */
    public static function getFixedDataProvider(): array
    {
        return [
            [0, 0],
            [1, 0.0000152587890625],
            [2, 0.0039520263671875],
            [3, 1.0118255615234375],
            [4, 259.027587890625],
            [5, 775.0590667724609375],
            [6, 1807.1220703125],
            [7, 3871.248046875],
            [8, 7999.4999847412109375],
            [9, 16255.999969482421875],
            [10, 32767.996032715],
            [11, -1.0118408203125],
            [12, -259.027587890625],
            [13, -775.05908203125],
            [14, -1807.1220703125],
            [15, -3871.248046875],
            [16, -7999.5],
            [17, -16255.99609375],
            [18, -32767.0000152587890625],
            [19, 255.9999847412109375],
            [20, -0.0000152587890625],
        ];
    }
}
