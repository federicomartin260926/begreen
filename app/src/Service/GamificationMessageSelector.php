<?php

namespace App\Service;

class GamificationMessageSelector
{
    /**
     * @param non-empty-list<string> $keys
     */
    public function select(array $keys): string
    {
        return $keys[random_int(0, count($keys) - 1)];
    }
}
