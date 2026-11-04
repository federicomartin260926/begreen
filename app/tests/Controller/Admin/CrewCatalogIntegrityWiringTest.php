<?php

namespace App\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Contratos estructurales E1: aseguran que las tres barreras siguen presentes.
 * No sustituyen las pruebas funcionales HTTP ni verifican transacciones reales.
 */
final class CrewCatalogIntegrityWiringTest extends TestCase
{
    private static function controllerSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Controller/Admin/CrewCatalogController.php');
        self::assertIsString($source);

        return $source;
    }

    public function testDepartmentDeletionChecksBothAssignmentsAndPositionsBeforeRemove(): void
    {
        $source = self::controllerSource();
        $start = strpos($source, 'public function deleteDepartment(');
        $end = strpos($source, 'public function positions(', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString("->count(['crewDepartment' => \$department])", $method);
        self::assertStringContainsString('!$department->getPositions()->isEmpty()', $method);
        self::assertLessThan(strpos($method, '$entityManager->remove($department)'), strpos($method, 'if ($assignmentCount > 0'));
    }

    public function testPositionDeletionChecksAssignmentsBeforeRemove(): void
    {
        $source = self::controllerSource();
        $start = strpos($source, 'public function deletePosition(');
        self::assertNotFalse($start);
        $method = substr($source, $start);
        self::assertStringContainsString("->count(['crewPosition' => \$position]) > 0", $method);
        self::assertLessThan(strpos($method, '$entityManager->remove($position)'), strpos($method, "->count(['crewPosition'"));
    }

    public function testMovingAssignedPositionIsBlockedAndLocaleRestored(): void
    {
        $source = self::controllerSource();
        $start = strpos($source, 'public function editPosition(');
        $end = strpos($source, 'public function deletePosition(', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString('$selectedDepartment->getId() !== $returnDepartment->getId()', $method);
        self::assertStringContainsString("->count(['crewPosition' => \$position]) > 0", $method);
        $restore = strpos($method, '$this->restoreLocale($request, $translatableListener);');
        $flash = strpos($method, 'No se puede cambiar de departamento un cargo');
        self::assertNotFalse($flash);
        self::assertNotFalse($restore);
        self::assertGreaterThan($flash, $restore);
    }
}
