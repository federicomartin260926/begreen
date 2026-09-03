<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AuxiliaryController;
use App\Entity\Department;
use PHPUnit\Framework\TestCase;

final class AuxiliaryCrewLegacyRemovalTest extends TestCase
{
    public function testDepartmentRemainsAvailableAndLegacyPositionIsRemoved(): void
    {
        $reflection = new \ReflectionClass(AuxiliaryController::class);
        $entityMap = $reflection->getReflectionConstant('ENTITY_MAP')?->getValue();

        self::assertIsArray($entityMap);
        self::assertSame(Department::class, $entityMap['department']);
        self::assertArrayNotHasKey('position', $entityMap);
    }
}
