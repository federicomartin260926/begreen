<?php

declare(strict_types=1);

namespace App\Exception\ReportDesign;

final class ReportDesignRequiresDevelopmentException extends \RuntimeException
{
    public const USER_MESSAGE = 'Este dato requiere habilitación de desarrollo antes de poder utilizarlo en el diseño.';

    public function __construct()
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
