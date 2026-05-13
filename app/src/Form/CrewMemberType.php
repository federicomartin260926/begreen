<?php

namespace App\Form;

use App\Entity\CrewMember;
use App\Entity\Department;
use App\Entity\Position;
use App\Repository\DepartmentRepository;
use App\Repository\PositionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CrewMemberType extends AbstractType
{
    public function __construct(
        private DepartmentRepository $departmentRepo,
        private PositionRepository $positionRepo
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $projectType = $options['projectType'] ?? null;

        // Campos básicos
        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.projects.crew.form.name',
                'attr'  => ['placeholder' => 'backend.projects.crew.form.name_ph'],
            ])
            ->add('lastName', TextType::class, [
                'label'    => 'backend.projects.crew.form.last_name',
                'required' => false,
                'attr'     => ['placeholder' => 'backend.projects.crew.form.last_name_ph'],
            ])
            ->add('email', EmailType::class, [
                'label'    => 'backend.projects.crew.form.email',
                'required' => false,
                'attr'     => ['placeholder' => 'backend.projects.crew.form.email_ph'],
            ])
            ->add('phone', TelType::class, [
                'label'    => 'backend.projects.crew.form.phone',
                'required' => false,
                'attr'     => ['placeholder' => 'backend.projects.crew.form.phone_ph'],
            ])
            ->add('department', EntityType::class, [
                'class'         => Department::class,
                'choice_label'  => 'name',
                'query_builder' => fn() => $this->departmentRepo->qbForProjectType($projectType),
                'required'      => false,
                'placeholder'   => 'backend.common.select',
                'label'         => 'backend.projects.crew.form.department',
                'attr'          => ['data-crew-target' => 'department'],
            ])
            // position: choices se definen en los eventos
            ->add('position', EntityType::class, [
                'class'        => Position::class,
                'choice_label' => 'name',
                'choices'      => [],
                'required'     => false,
                'placeholder'  => 'backend.common.select',
                'label'        => 'backend.projects.crew.form.position',
                'attr'         => ['data-crew-target' => 'position'],
            ])
        ;

        // Al cargar datos (edición)
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            /** @var CrewMember|null $cm */
            $cm   = $event->getData();
            $form = $event->getForm();
            if (!$cm) return;

            $dept    = $cm->getDepartment() ?: $cm->getPosition()?->getDepartment();
            $choices = $dept ? $this->positionRepo->findByDepartment($dept) : [];

            $form->add('position', EntityType::class, [
                'class'        => Position::class,
                'choice_label' => 'name',
                'choices'      => $choices,
                'required'     => false,
                'placeholder'  => 'backend.common.select',
                'label'        => 'backend.projects.crew.form.position',
                'data'         => $cm->getPosition(),
                'attr'         => ['data-crew-target' => 'position'],
            ]);
        });

        // Al enviar (maneja cambio de departamento)
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData() ?? [];
            $form = $event->getForm();

            $dept = null;
            if (!empty($data['department'])) {
                $deptId = (int) $data['department'];
                $dept   = $deptId ? $this->departmentRepo->find($deptId) : null;
            } elseif (!empty($data['position'])) {
                $pos  = $this->positionRepo->find((int) $data['position']);
                $dept = $pos?->getDepartment();
            }

            $choices = $dept ? $this->positionRepo->findByDepartment($dept) : [];

            if (!$dept) {
                $data['position'] = null;
                $event->setData($data);
            }

            $form->add('position', EntityType::class, [
                'class'        => Position::class,
                'choice_label' => 'name',
                'choices'      => $choices,
                'required'     => false,
                'placeholder'  => 'backend.common.select',
                'label'        => 'backend.projects.crew.form.position',
                'attr'         => ['data-crew-target' => 'position'],
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'  => CrewMember::class,
            'projectType' => null, // 'rodaje' | 'evento' | null
            // 'translation_domain' => 'messages',  // opcional si tu default no es "messages"
        ]);
    }
}
