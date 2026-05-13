<?php

namespace App\DataFixtures;

use App\Entity\HomeCounter;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class HomeCounterFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $data = [
            ['eventos', 90],
            ['rodajes', 30],
            ['compensado', 50],
        ];

        foreach ($data as [$type, $value]) {
            $counter = new HomeCounter();
            $counter->setType($type);
            $counter->setValue($value);
            $manager->persist($counter);
        }

        $manager->flush();
    }
}
