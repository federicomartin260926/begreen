<?php

namespace App\Tests\Security;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Form\UserType;
use App\Security\PlanVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RoleVerifierConfigurationTest extends KernelTestCase
{
    public function testVerifierInheritsUserButNotAdmin(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $user = (new User())
            ->setName('Verifier')
            ->setSurnames('User')
            ->setEmail('verifier.hierarchy@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_VERIFIER']);
        $container->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );

        $authorizationChecker = $container->get(AuthorizationCheckerInterface::class);

        self::assertTrue($authorizationChecker->isGranted('ROLE_USER'));
        self::assertTrue($authorizationChecker->isGranted('ROLE_VERIFIER'));
        self::assertFalse($authorizationChecker->isGranted('ROLE_ADMIN'));
        self::assertFalse($authorizationChecker->isGranted('ROLE_SUPER_ADMIN'));
    }

    public function testVerifierIsAvailableInUserAdministrationForm(): void
    {
        self::bootKernel();
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(UserType::class, new User());
        $choices = $form->get('roles')->getConfig()->getOption('choices');

        self::assertSame('ROLE_VERIFIER', $choices['backend.roles.ROLE_VERIFIER'] ?? null);
    }

    public function testVerifyCapabilityIsResolvedThroughRealMembership(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $suffix = uniqid();
        $user = (new User())
            ->setName('Verifier')
            ->setSurnames('Membership')
            ->setEmail('verifier.membership.'.$suffix.'@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_VERIFIER']);
        $assignedProject = $this->project('Assigned '.$suffix, $user);
        $otherProject = $this->project('Other '.$suffix, $user);
        $assignedPlan = (new Plan())->setProject($assignedProject)->setUser($user);
        $otherPlan = (new Plan())->setProject($otherProject)->setUser($user);
        $membership = (new ProjectMembership())
            ->setUser($user)
            ->setProject($assignedProject)
            ->setProjectRole('member');

        foreach ([$user, $assignedProject, $otherProject, $assignedPlan, $otherPlan, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $container->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );

        $authorizationChecker = $container->get(AuthorizationCheckerInterface::class);

        self::assertTrue($authorizationChecker->isGranted(PlanVoter::VERIFY, $assignedPlan));
        self::assertFalse($authorizationChecker->isGranted(PlanVoter::VERIFY, $otherPlan));
    }

    private function project(string $name, User $owner): Project
    {
        $project = (new Project())
            ->setName($name)
            ->setType('rodaje')
            ->setCountry('ES')
            ->setUser($owner);

        foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $phase) {
            $project->addSubscription(
                (new ProjectSubscription())
                    ->setPhase($phase)
                    ->setTier(ProjectSubscription::TIER_BASIC)
                    ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                    ->setSource(ProjectSubscription::SOURCE_SYSTEM)
            );
        }

        return $project;
    }
}
