<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * @internal random ids in hex
 */
final class Ids
{
    /** @param int<1, max> $bytes */
    public static function new(int $bytes): string
    {
        $id = bin2hex(random_bytes($bytes));

        return trim($id, '0') === '' ? substr($id, 0, -1) . '1' : $id; // all zeros is not a valid id
    }
}
