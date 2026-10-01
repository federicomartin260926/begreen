<?php

namespace App\Exception\CrewImport;

final class CrewImportReviewValidationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The crew import review contains invalid data.');
    }
}
