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
            [6, 1_807],
            [7, 3_871],
            [8, 7_999],
            [9, 16_255],
            [10, 32_767],
            [11, 65_534],
            [12, 65_276],
            [13, 64_760],
            [14, 63_728],
            [15, 61_664],
            [16, 57_536],
            [17, 49_280],
            [18, 32_768],
            [19, 255],
            [20, 65_535],
            [21, 65_535],
            [22, 65_535],
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
            [6, 1_807],
            [7, 3_871],
            [8, 7_999],
            [9, 16_255],
            [10, 32_767],
            [11, -2],
            [12, -260],
            [13, -776],
            [14, -1_808],
            [15, -3_872],
            [16, -8_000],
            [17, -16_256],
            [18, -32_768],
            [19, 255],
            [20, -1],
            [21, -1],
            [22, -1],
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
            [3, 66_311],
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

    #[DataProvider('getFixedDataProvider')]
    public function testGetFixed(int $offset, int|float $expected): void
    {
        $byte = $this->getTestObject();
        $res = $byte->getFixed($offset);
        // compare floats with a small tolerance to avoid precision issues
        $this->assertEqualsWithDelta($expected, $res, 1e-12, "float mismatch at offset $offset");
    }
    /**
     * @return array<array{int, float}>
     */
    public static function getFixedDataProvider(): array
    {
        return [
            // high and low parts from the test string; see getShort/getUShort providers
            // offset 0: all zero bytes
            [0, 0.0],
            // offset 1: high=0, low=1 -> 1/65536
            [1, 1.52587890625E-5],
            // offset 2: high=0, low=259 -> 259/65536
            [2, 0.0039520263671875],
            // offset 3: high=1, low=775 -> 1 + 775/65536
            [3, 1.0118255615234375],
            // a negative result with fractional part
            [11, -1.0118408203125],
            // large positive value near next integer
            [19, 255.99998474121094],
            // negative value very close to zero (tiny fraction)
            [20, -1.52587890625E-5],
        ];
    }
}
