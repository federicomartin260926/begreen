<?php

namespace App\Form;

use App\Entity\CrewDepartment;
use App\Entity\CrewMemberAssignment;
use App\Entity\CrewPosition;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CrewMemberAssignmentType extends AbstractType
{
    public function __construct(
        private readonly CrewDepartmentRepository $departmentRepository,
        private readonly CrewPositionRepository $positionRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $scope = $options['crew_scope'];

        $builder->add('crewDepartment', EntityType::class, [
            'class' => CrewDepartment::class,
            'choice_label' => 'name',
            'choices' => $this->departmentRepository->findByScope($scope),
            'required' => true,
            'placeholder' => 'backend.common.select',
            'label' => 'backend.projects.crew.form.department',
            'attr' => [
                'data-action' => 'change->crew#departmentChanged',
                'data-crew-assignment-department' => '',
            ],
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($scope): void {
            $assignment = $event->getData();
            $department = $assignment instanceof CrewMemberAssignment
                ? $assignment->getCrewDepartment()
                : null;

            if ($department?->getScope() !== $scope) {
                $department = null;
            }

            $this->addPositionField($event->getForm(), $department);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($scope): void {
            $data = $event->getData();
            $department = null;

            if (is_array($data) && is_scalar($data['crewDepartment'] ?? null)) {
                $departmentId = filter_var($data['crewDepartment'], FILTER_VALIDATE_INT);
                $candidate = $departmentId ? $this->departmentRepository->find($departmentId) : null;

                if ($candidate?->getScope() === $scope) {
                    $department = $candidate;
                }
            }

            $this->addPositionField($event->getForm(), $department);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CrewMemberAssignment::class,
        ]);
        $resolver->setRequired('crew_scope');
        $resolver->setAllowedTypes('crew_scope', 'string');
        $resolver->setAllowedValues('crew_scope', CrewDepartment::SCOPES);
    }

    private function addPositionField(FormInterface $form, ?CrewDepartment $department): void
    {
        $form->add('crewPosition', EntityType::class, [
            'class' => CrewPosition::class,
            'choice_label' => 'name',
            'choices' => $department ? $this->positionRepository->findByCrewDepartment($department) : [],
            'required' => false,
            'placeholder' => 'backend.common.select',
            'label' => 'backend.projects.crew.form.position',
            'attr' => ['data-crew-assignment-position' => ''],
        ]);
    }
}
