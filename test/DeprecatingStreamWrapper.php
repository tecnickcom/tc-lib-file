<?php

/**
 * DeprecatingStreamWrapper.php
 *
 * @since     2026-08-26
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
 * A stream wrapper that raises an E_USER_DEPRECATED on read.
 *
 * The warning-suppression helpers in File and Cache swallow E_WARNING and
 * E_NOTICE only. This wrapper raises a level outside that set from inside a
 * suppressed call, so a test can assert that it still reaches the error handler
 * the application installed.
 */
class DeprecatingStreamWrapper
{
    /**
     * Message raised on the first read.
     */
    public const MESSAGE = 'a deprecation from inside a suppressed call';

    public mixed $context;

    private bool $done = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        unset($path, $mode, $options, $opened_path);
        return true;
    }

    public function stream_read(int $count): string
    {
        unset($count);
        if ($this->done) {
            return '';
        }

        $this->done = true;
        \trigger_error(self::MESSAGE, E_USER_DEPRECATED);

        return 'ok';
    }

    public function stream_eof(): bool
    {
        return $this->done;
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
