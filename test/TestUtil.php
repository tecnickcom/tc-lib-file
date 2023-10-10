<?php

/**
 * TestUtil.php
 *
 * @since       2020-12-19
 * @category    Library
 * @package     file
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2015-2021 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-file
 *
 * This file is part of tc-lib-file software library.
 */

namespace Test;

use PHPUnit\Framework\TestCase;

/**
 * Test Util
 *
 * @since      2020-12-19
 * @category    Library
 * @package     file
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2015-2021 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-file
 */
class TestUtil extends TestCase
{
    public function bcAssertEqualsWithDelta($expected, $actual, $delta = 0.01, $message = '')
    {
        if (\is_callable([self::class, 'assertEqualsWithDelta'])) {
            parent::assertEqualsWithDelta($expected, $actual, $delta, $message);
            return;
        }
        return $this->assertEquals($expected, $actual, $message, $delta);
    }

    public function bcExpectException($exception)
    {
        if (\is_callable([self::class, 'expectException'])) {
            parent::expectException($exception);
            return;
        }
        return parent::setExpectedException($exception);
    }

    public function bcAssertIsResource($res)
    {
        if (\is_callable([self::class, 'assertIsResource'])) {
            parent::assertIsResource($res);
            return;
        }
        return parent::assertInternalType('resource', $res);
    }

    public function bcAssertMatchesRegularExpression($pattern, $string, $message = '')
    {
        if (\is_callable([self::class, 'assertMatchesRegularExpression'])) {
            parent::assertMatchesRegularExpression($pattern, $string, $message);
            return;
        }
        return parent::assertRegExp($pattern, $string, $message);
    }
}
