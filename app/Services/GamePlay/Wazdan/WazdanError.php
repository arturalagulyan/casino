<?php

namespace App\Services\GamePlay\Wazdan;

use RuntimeException;

/** A Wazdan reply other than "ok" — `reply` is the whole frame. */
class WazdanError extends RuntimeException
{
    /** @param array<string, mixed> $reply */
    public function __construct(public readonly string $act, public readonly array $reply)
    {
        parent::__construct("Wazdan {$act}: ".mb_strimwidth(json_encode($reply, JSON_UNESCAPED_SLASHES) ?: '', 0, 400));
    }
}
