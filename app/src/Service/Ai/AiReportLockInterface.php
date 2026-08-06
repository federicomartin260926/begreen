<?php

namespace App\Service\Ai;

interface AiReportLockInterface
{
    public function synchronized(string $key, callable $callback): mixed;
}
