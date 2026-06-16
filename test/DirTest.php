<?php

declare(strict_types=1);

/**
 * DirTest.php
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

use Com\Tecnick\File\Dir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * File Color class test
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
class DirTest extends TestUtil
{
    protected function getTestObject(): Dir
    {
        return new Dir();
    }

    #[Test]
    #[DataProvider('getAltFilePathsDataProvider')]
    public function testGetAltFilePaths(string $name, string $expected): void
    {
        $testObj = $this->getTestObject();
        $dir = $testObj->findParentDir($name);
        $this->bcAssertMatchesRegularExpression('#' . $expected . '#', $dir);
    }

    /**
     * @return array<array{string, string}>
     */
    public static function getAltFilePathsDataProvider(): array
    {
        $paths = [['', '/src/'], ['missing', '/'], ['src', '/src/']];

        // Handle Windows directory separators
        if (\DIRECTORY_SEPARATOR !== '/') {
            foreach ($paths as &$path) {
                $path[1] = \str_replace('/', \preg_quote(\DIRECTORY_SEPARATOR, '#'), $path[1]);
            }
        }

        return $paths;
    }
}
