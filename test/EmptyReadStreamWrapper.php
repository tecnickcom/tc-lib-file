<?php

/**
 * EmptyReadStreamWrapper.php
 *
 * @since     2026-04-30
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
 * A stream wrapper that returns data on the first read, then empty strings on
 * every subsequent read, and never signals EOF through stream_eof().
 *
 * Exercises the inner break in File::rfRead().
 */
class EmptyReadStreamWrapper
{
    public mixed $context;

    private string $initialData = 'ab';

    private bool $initialRead = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        unset($path, $mode, $options, $opened_path);
        return true;
    }

    public function stream_read(int $count): string
    {
        unset($count);
        if (!$this->initialRead) {
            $this->initialRead = true;
            return $this->initialData;
        }

        // An empty string while not at EOF makes the loop in rfRead() re-enter
        // and hit the inner break.
        return '';
    }

    public function stream_eof(): bool
    {
        // Never signals EOF: stream_read() returns '' instead.
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
