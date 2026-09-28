<?php

namespace App\Tests\Service\ProjectDocument;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Enum\ProjectDocumentCatalog;
use PHPUnit\Framework\TestCase;

final class ProjectDocumentTest extends TestCase
{
    public function testProjectAssociationIsBidirectional(): void
    {
        $project = new Project();
        $document = (new ProjectDocument())
            ->setType('script')
            ->setKind(ProjectDocumentCatalog::KIND_LINK)
            ->setUrl('https://example.com/script');

        $project->addProjectDocument($document);

        self::assertSame($project, $document->getProject());
        self::assertTrue($project->getProjectDocuments()->contains($document));

        $project->removeProjectDocument($document);

        self::assertNull($document->getProject());
        self::assertFalse($project->getProjectDocuments()->contains($document));
    }

    public function testOtherTypeIsNormalized(): void
    {
        $document = (new ProjectDocument())->setOtherType('  Documento propio  ');

        self::assertSame('Documento propio', $document->getOtherType());

        $document->setOtherType('   ');
        self::assertNull($document->getOtherType());
    }
}
