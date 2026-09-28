<?php

namespace App\Form;

use App\Entity\Project;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Service\CommercialPlanResolver;
use App\Service\Animation\AnimationConfiguration;
use InvalidArgumentException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectType extends AbstractType
{
    public function __construct(private readonly CommercialPlanResolver $commercialPlanResolver)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $project = $builder->getData();
        $filmingGenreChoices = ProjectCatalog::filmingGenreChoices();
        if ($project instanceof Project
            && 'tv_program' === $project->getFilmingType()
            && is_string($project->getFilmingGenre())) {
            $filmingGenreChoices += ProjectCatalog::legacyFilmingGenreChoice($project->getFilmingGenre());
        }

        $eventTypes = [
            'backend.projects.form.event_type_primary.options.cultural' => 'cultural',
            'backend.projects.form.event_type_primary.options.deportivo' => 'deportivo',
            'backend.projects.form.event_type_primary.options.corporativo' => 'corporativo',
            'backend.projects.form.event_type_primary.options.academico' => 'academico',
            'backend.projects.form.event_type_primary.options.politico' => 'politico',
            'backend.projects.form.event_type_primary.options.religioso' => 'religioso',
            'backend.projects.form.event_type_primary.options.solidario' => 'solidario',
            'backend.projects.form.event_type_primary.options.social' => 'social',
        ];

        $modalities = [
            'backend.projects.form.event_modality.options.presencial' => 'presencial',
            'backend.projects.form.event_modality.options.virtual' => 'virtual',
            'backend.projects.form.event_modality.options.hibrido' => 'hibrido',
        ];

        $showCommercialTier = (bool) $options['show_commercial_tier'];
        $showEmissionSource = (bool) $options['show_emission_source'];
        $commercialTierValue = (string) ($options['commercial_tier_value'] ?? 'basic');
        $basicPlan = $this->commercialPlanResolver->getPlanByCode(CommercialPhase::ELABORATION, 'basic');
        $standardPlan = $this->commercialPlanResolver->getPlanByCode(CommercialPhase::ELABORATION, 'standard');
        $proPlan = $this->commercialPlanResolver->getPlanByCode(CommercialPhase::ELABORATION, 'pro');

        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.projects.form.name',
                'attr' => ['placeholder' => 'backend.projects.form.name_ph'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'backend.projects.form.type',
                'placeholder' => 'backend.projects.form.type_placeholder',
                'choices' => [
                    'backend.projects.form.project_kind.audiovisual' => 'rodaje',
                    'backend.projects.form.project_kind.event' => 'evento',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => [
                    'data-project-target' => 'type',
                    'data-action' => 'change->project#change',
                ],
            ])
            ->add('country', CountryType::class, [
                'label' => 'backend.projects.form.country',
                'placeholder' => 'backend.projects.form.country_placeholder',
            ])
            ->add('commercialTier', ChoiceType::class, [
                'label' => 'backend.projects.form.commercial_tier',
                'mapped' => false,
                'required' => false,
                'choices' => [
                    $basicPlan->getName() => 'basic',
                    $standardPlan->getName() => 'standard',
                    $proPlan->getName() => 'pro',
                ],
                'choice_translation_domain' => false,
                'placeholder' => false,
                'data' => $commercialTierValue,
                'disabled' => !$showCommercialTier,
                'row_attr' => $showCommercialTier ? [] : ['class' => 'd-none'],
            ])

            ->add('filmingType', ChoiceType::class, [
                'label' => 'backend.projects.form.filming_type.label',
                'required' => false,
                'placeholder' => 'backend.common.placeholder',
                'choices' => ProjectCatalog::filmingTypeChoices(),
                'choice_translation_domain' => 'messages',
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'attr' => [
                    'data-project-target' => 'filmingType',
                    'data-action' => 'change->project#change',
                    'data-required-when' => 'type:rodaje',
                ],
            ])
            ->add('filmingGenre', ChoiceType::class, [
                'label' => 'backend.projects.form.filming_genre.label',
                'required' => false,
                'placeholder' => 'backend.common.placeholder',
                'choices' => $filmingGenreChoices,
                'choice_translation_domain' => 'messages',
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'attr' => [
                    'data-project-target' => 'filmingGenre',
                    'data-action' => 'change->project#change',
                ],
            ])
            ->add('distributionMedia', ChoiceType::class, [
                'label' => 'backend.projects.form.distribution_media.label',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => ProjectCatalog::distributionMediaChoices(),
                'choice_translation_domain' => 'messages',
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'attr' => ['class' => 'distribution-media-grid'],
                'choice_attr' => static fn (): array => [
                    'data-required-group-when' => 'type:rodaje',
                ],
            ])
            ->add('episodios', IntegerType::class, [
                'label' => 'backend.projects.form.common.episodios',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'min' => 1,
                    'step' => 1,
                    'data-project-target' => 'episodios',
                    'data-required-when' => 'filmingType:tv_series,filmingType:tv_program',
                ],
            ])
            ->add('duracionEpisodio', IntegerType::class, [
                'label' => 'backend.projects.form.common.duracion_episodio',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'min' => 1,
                    'step' => 1,
                    'data-project-target' => 'duracionEpisodio',
                    'data-required-when' => 'filmingType:tv_series,filmingType:tv_program',
                ],
            ])

            ->add('eventTypePrimary', ChoiceType::class, [
                'label' => 'backend.projects.form.event_type_primary.label',
                'required' => false,
                'choices' => $eventTypes,
                'placeholder' => 'backend.common.placeholder',
                'choice_translation_domain' => 'messages',
                'row_attr' => ['data-show-when' => 'type:evento'],
                'attr' => [
                    'data-required-when' => 'type:evento',
                ],
            ])
            ->add('eventModality', ChoiceType::class, [
                'label' => 'backend.projects.form.event_modality.label',
                'required' => false,
                'choices' => $modalities,
                'placeholder' => 'backend.common.placeholder',
                'choice_translation_domain' => 'messages',
                'row_attr' => ['data-show-when' => 'type:evento'],
                'attr' => [
                    'data-project-target' => 'eventModality',
                    'data-action' => 'change->project#change',
                    'data-required-when' => 'type:evento',
                ],
            ])
            ->add('eventAttendeesCount', IntegerType::class, [
                'label' => 'backend.projects.form.event_attendees_count.label',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'min' => 0,
                    'step' => 1,
                    'class' => 'form-control',
                    'data-required-when' => 'eventModality:presencial,eventModality:hibrido',
                ],
                'row_attr' => ['data-show-when' => 'eventModality:presencial,eventModality:hibrido'],
            ])
            ->add('eventOnlineConnections', IntegerType::class, [
                'label' => 'backend.projects.form.event_online_connections.label',
                'required' => false,
                'empty_data' => null,
                'attr' => [
                    'min' => 0,
                    'step' => 1,
                    'class' => 'form-control',
                    'data-required-when' => 'eventModality:virtual,eventModality:hibrido',
                ],
                'row_attr' => ['data-show-when' => 'eventModality:virtual,eventModality:hibrido'],
            ])

            ->add('mainLocation', TextType::class, [
                'label' => 'backend.projects.form.main_location',
                'required' => false,
            ])
            ->add('presupuesto', NumberType::class, [
                'label' => 'backend.projects.form.budget_eur',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'empty_data' => null,
                'attr' => [
                    'min' => 0,
                    'step' => '0.01',
                ],
            ])
            ->add('projectCompanies', CollectionType::class, [
                'entry_type' => ProjectCompanyType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__company__',
            ])
            ->add('projectDocuments', CollectionType::class, [
                'entry_type' => ProjectDocumentType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__document__',
            ])
            ->add('projectFundingSources', CollectionType::class, [
                'entry_type' => ProjectFundingSourceType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__funding__',
            ])
            ->add('ecoManagerStatus', ChoiceType::class, [
                'label' => 'backend.projects.form.eco_manager_status.label',
                'required' => false,
                'choices' => ProjectCatalog::ecoManagerStatusChoices(),
                'choice_translation_domain' => 'messages',
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
            ])

            ->add('phaseDates', CollectionType::class, [
                'entry_type' => ProjectPhaseDateType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => ['class' => 'phase-entry-wrapper'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__phase__',
                'attr' => [
                    'data-project-target' => 'list',
                ],
            ]);

        $configuration = $project instanceof Project
            ? $project->getAnimationConfiguration()
            : null;
        $builder->add('animationConfiguration', AnimationProjectConfigurationType::class, [
            'mapped' => false,
            'label' => false,
            'data' => null === $configuration ? [] : [
                'techniques' => $configuration->getTechniques(),
                'shootingAnswered' => $configuration->getShootingAnswered(),
                'structure' => $configuration->getStructure(),
                'processingLevel' => $configuration->getProcessingLevel(),
                'processingInfrastructures' => $configuration->getProcessingInfrastructures(),
                'usesAi' => $configuration->getUsesAi(),
            ],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $project = $event->getData();
            $form = $event->getForm();
            if (!$project instanceof Project
                || 'rodaje' !== $project->getType()
                || ProjectCatalog::FILMING_GENRE_ANIMATION !== $project->getFilmingGenre()) {
                return;
            }

            $animationForm = $form->get('animationConfiguration');
            $data = $animationForm->getData();
            if (!is_array($data)) {
                $animationForm->addError(new FormError('backend.projects.form.validation.animation_incomplete'));

                return;
            }

            try {
                $configuration = new AnimationConfiguration(
                    techniques: is_array($data['techniques'] ?? null) ? $data['techniques'] : [],
                    structure: is_string($data['structure'] ?? null) ? $data['structure'] : null,
                    shootingAnswered: is_bool($data['shootingAnswered'] ?? null) ? $data['shootingAnswered'] : null,
                    processingInfrastructures: is_array($data['processingInfrastructures'] ?? null) ? $data['processingInfrastructures'] : [],
                    processingLevel: is_string($data['processingLevel'] ?? null) ? $data['processingLevel'] : null,
                    usesAi: is_bool($data['usesAi'] ?? null) ? $data['usesAi'] : null,
                    distribution: [] === $project->getDistributionMedia() ? null : $project->getDistributionMedia(),
                    plan: null,
                );
                $configuration->assertComplete(requirePlan: false);
            } catch (InvalidArgumentException) {
                $animationForm->addError(new FormError('backend.projects.form.validation.animation_incomplete'));
            }
        });

        if ($showEmissionSource) {
            $builder->add('emissionSourceName', ChoiceType::class, [
                'label' => 'backend.projects.form.emission_source',
                'choices' => [
                    'backend.projects.form.emission_source_options.miteco' => Project::DEFAULT_EMISSION_SOURCE_NAME,
                    'backend.projects.form.emission_source_options.defra' => 'DEFRA',
                ],
                'required' => true,
                'attr' => ['class' => 'form-select'],
                'choice_translation_domain' => 'messages',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Project::class,
            'show_commercial_tier' => false,
            'show_emission_source' => true,
            'commercial_tier_value' => 'basic',
        ]);
    }
}
