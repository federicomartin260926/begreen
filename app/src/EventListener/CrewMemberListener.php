<?php

namespace App\EventListener;

use App\Entity\CrewMember;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class CrewMemberListener
{
    public function prePersist(CrewMember $cm, LifecycleEventArgs $args): void
    {
        $this->syncDept($cm);
    }

    public function preUpdate(CrewMember $cm, PreUpdateEventArgs $args): void
    {
        $this->syncDept($cm);
    }

    private function syncDept(CrewMember $cm): void
    {
        if ($cm->getPosition() && !$cm->getDepartment()) {
            $cm->setDepartment($cm->getPosition()->getDepartment());
        }
    }
}
