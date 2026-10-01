<?php

namespace App\Service\CrewImport;

final class CrewImportWarning
{
    public const NAME_REQUIRED = 'NAME_REQUIRED';
    public const UNKNOWN_DEPARTMENT = 'UNKNOWN_DEPARTMENT';
    public const UNKNOWN_POSITION = 'UNKNOWN_POSITION';
    public const AMBIGUOUS_POSITION = 'AMBIGUOUS_POSITION';
    public const POSITION_DEPARTMENT_MISMATCH = 'POSITION_DEPARTMENT_MISMATCH';
    public const PERSON_IDENTITY_CONFLICT = 'PERSON_IDENTITY_CONFLICT';
    public const DUPLICATE_IN_FILE = 'DUPLICATE_IN_FILE';

    private function __construct()
    {
    }
}
