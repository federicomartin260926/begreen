<?php

namespace App\Service\Emission;

final class EmissionRecordAttachmentValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly string $errorKey)
    {
        parent::__construct($errorKey);
    }
}
