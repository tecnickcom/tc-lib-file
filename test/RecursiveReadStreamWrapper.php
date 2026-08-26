<?php

/**
 * RecursiveReadStreamWrapper.php
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
 * A stream wrapper that returns a short chunk on the first stream_read() call
 * and the requested count afterwards.
 */
class RecursiveReadStreamWrapper
{
    public mixed $context;

    private string $data = 'abcde';

    private int $position = 0;

    private int $reads = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        unset($path, $mode, $options, $opened_path);
        return true;
    }

    public function stream_read(int $count): string
    {
        ++$this->reads;
        $length = $this->reads === 1 ? \min(2, $count) : $count;
        $chunk = \substr($this->data, $this->position, $length);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \strlen($this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
