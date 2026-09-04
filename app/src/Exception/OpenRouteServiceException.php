<?php

namespace App\Exception;

final class OpenRouteServiceException extends \RuntimeException
{
    public const NOT_ROUTABLE = 'not_routable';
    public const UPSTREAM_FAILURE = 'upstream_failure';

    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('OpenRouteService request failed.', 0, $previous);
    }
}
