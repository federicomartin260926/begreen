<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Symfony\Bundle\SecurityBundle\Security;

class ActiveProjectService
{
    private const SESSION_KEY = 'active_project_id';

    public function __construct(
        private RequestStack $requestStack,
        private Security $security,
        private ProjectRepository $projectRepository
    ) {}

    public function getActiveProject(): ?Project
    {
        $session = $this->requestStack->getSession();
        $projectId = $session->get(self::SESSION_KEY);

        if ($projectId) {
            return $this->projectRepository->find($projectId);
        }

        $user = $this->security->getUser();
        if ($user) {

            $projects = $this->projectRepository->findBy(['user' => $user]);
            if (count($projects) === 1) {
                $this->setActiveProject($projects[0]);
                return $projects[0];
            }
        }

        return null;
    }

    public function setActiveProject(Project $project): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $project->getId());
    }

    public function setDefaultProject(): void
    {
        $user = $this->security->getUser();
        if ($user) {
            $projects = $this->projectRepository->findBy(['user' => $user]);
            if (count($projects) > 0) {
                $this->setActiveProject($projects[0]);
            }
        }
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }
}
