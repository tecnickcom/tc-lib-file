<?php

/**
 * ExceptionTest.php
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 *
 * This file is part of tc-lib-file software library.
 */

namespace Test;

/**
 * Exception class test
 *
 * @since     2015-07-28
 * @category  Library
 * @package   File
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-file
 */
class ExceptionTest extends TestUtil
{
    /**
     * Catching \Exception must catch the library exception, which is what the
     * documented @throws contracts rely on.
     */
    public function testIsAnException(): void
    {
        $exception = new \Com\Tecnick\File\Exception('message');

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }

    public function testPassesThroughMessageCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new \Com\Tecnick\File\Exception('unable to read the file', 42, $previous);

        $this->assertSame('unable to read the file', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testDefaults(): void
    {
        $exception = new \Com\Tecnick\File\Exception();

        $this->assertSame('', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }
}
