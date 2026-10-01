<?php

namespace App\Service\CrewImport;

use App\Entity\Project;
use App\Service\CrewImport\Dto\CrewImportInterpretedRow;
use App\Service\CrewImport\Dto\CrewImportTabularDocument;

interface CrewImportAiInterpreterInterface
{
    /** @return list<CrewImportInterpretedRow> */
    public function interpret(Project $project, CrewImportTabularDocument|string $content): array;
}
