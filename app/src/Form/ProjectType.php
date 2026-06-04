<?php

namespace App\Form;

use App\Entity\Project;
use App\Form\ProjectPhaseDateType;
use App\Service\CommercialPlanResolver;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectType extends AbstractType
{
    public function __construct(private readonly CommercialPlanResolver $commercialPlanResolver)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // ====== Opciones de selects (todas con i18n) ======
        $filmingTypes = [
            'backend.projects.form.filming_type.options.feature'   => 'feature',
            'backend.projects.form.filming_type.options.short'     => 'short',
            'backend.projects.form.filming_type.options.tv_series' => 'tv_series',
            'backend.projects.form.filming_type.options.tv_program'=> 'tv_program',
        ];

        $genreGeneric = [
            'backend.projects.form.filming_genre.options.ficcion'      => 'ficcion',
            'backend.projects.form.filming_genre.options.documental'   => 'documental',
            'backend.projects.form.filming_genre.options.animacion'    => 'animacion',
            'backend.projects.form.filming_genre.options.experimental' => 'experimental',
        ];

        $genreTvProgram = [
            'backend.projects.form.filming_genre.options.informativo'     => 'informativo',
            'backend.projects.form.filming_genre.options.entretenimiento' => 'entretenimiento',
            'backend.projects.form.filming_genre.options.cultural'        => 'cultural',
            'backend.projects.form.filming_genre.options.educativo'       => 'educativo',
            'backend.projects.form.filming_genre.options.religioso'       => 'religioso',
        ];

        $eventTypes = [
            'backend.projects.form.event_type_primary.options.cultural'    => 'cultural',
            'backend.projects.form.event_type_primary.options.deportivo'   => 'deportivo',
            'backend.projects.form.event_type_primary.options.corporativo' => 'corporativo',
            'backend.projects.form.event_type_primary.options.academico'   => 'academico',
            'backend.projects.form.event_type_primary.options.politico'    => 'politico',
            'backend.projects.form.event_type_primary.options.religioso'   => 'religioso',
            'backend.projects.form.event_type_primary.options.solidario'   => 'solidario',
            'backend.projects.form.event_type_primary.options.social'      => 'social',
        ];

        $modalities = [
            'backend.projects.form.event_modality.options.presencial' => 'presencial',
            'backend.projects.form.event_modality.options.virtual'    => 'virtual',
            'backend.projects.form.event_modality.options.hibrido'    => 'hibrido',
        ];

        $assistants = [
            'backend.projects.form.event_attendees.options.presencial' => 'presencial',
            'backend.projects.form.event_attendees.options.virtual'    => 'virtual',
        ];

        $showCommercialTier = (bool) $options['show_commercial_tier'];
        $commercialTierValue = (string) ($options['commercial_tier_value'] ?? 'basic');
        $basicPlan = $this->commercialPlanResolver->getPlanByCode('basic');
        $standardPlan = $this->commercialPlanResolver->getPlanByCode('standard');
        $proPlan = $this->commercialPlanResolver->getPlanByCode('pro');

        $builder
            // ====== Datos básicos ======
            ->add('name', TextType::class, [
                'label' => 'backend.projects.form.name',
                'attr'  => ['placeholder' => 'backend.projects.form.name_ph'],
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'backend.projects.form.type',
                'choices' => [
                    'backend.aux.project_type.filming' => 'rodaje',
                    'backend.aux.project_type.event'   => 'evento',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => [
                    'data-project-target' => 'type',
                    'data-action'         => 'change->project#change',
                ],
            ])
            ->add('emissionSourceName', ChoiceType::class, [
                'label'   => 'backend.projects.form.emission_source',
                'choices' => [
                    'backend.projects.form.emission_source_options.miteco' => 'MITECO',
                    'backend.projects.form.emission_source_options.defra'  => 'DEFRA',
                ],
                'data'     => 'MITECO',
                'required' => true,
                'attr'     => ['class' => 'form-select'],
                'choice_translation_domain' => 'messages',
            ])
            ->add('country', CountryType::class, [
                'label' => 'backend.projects.form.country',
                'data'  => 'ES',
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

            // ====== Rodaje: tipo + género dependiente ======
            ->add('filmingType', ChoiceType::class, [
                'label'       => 'backend.projects.form.filming_type.label',
                'required'    => false,
                'placeholder' => 'backend.common.placeholder',
                'choices'     => $filmingTypes,
                'choice_translation_domain' => 'messages',
                'row_attr'    => ['data-show-when' => 'type:rodaje'],
                'attr'        => [
                    'data-project-target' => 'filmingType',
                    'data-action'         => 'change->project#onFilmingTypeChange',
                ],
            ])
            ->add('filmingGenre', ChoiceType::class, [
                'label'       => 'backend.projects.form.filming_genre.label',
                'required'    => false,
                'placeholder' => 'backend.common.placeholder',
                // Unión de ambos grupos para que funcione sin JS; Stimulus podrá filtrar luego
                'choices'     => $genreGeneric + $genreTvProgram,
                'choice_translation_domain' => 'messages',
                'row_attr'    => ['data-show-when' => 'type:rodaje'],
                'attr'        => [
                    'data-project-target'      => 'filmingGenre',
                    // pasamos los sets como data-* para que el JS pueda rehacer opciones si lo deseas
                    'data-genre-generic'       => json_encode(array_values($genreGeneric)),
                    'data-genre-tvprogram'     => json_encode(array_values($genreTvProgram)),
                ],
            ])

            // ====== Rodaje: checkboxes ======
            ->add('liveTv', CheckboxType::class, [
                'label'    => 'backend.projects.form.live_tv',
                'required' => false,
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'property_path' => 'isLiveTv',
            ])
            ->add('advert', CheckboxType::class, [
                'label'    => 'backend.projects.form.advert',
                'required' => false,
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'property_path' => 'isAdvert',
            ])
            ->add('corporateVideo', CheckboxType::class, [
                'label'    => 'backend.projects.form.corporate_video',
                'required' => false,
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'property_path' => 'isCorporateVideo',
            ])
            ->add('musicVideo', CheckboxType::class, [
                'label'    => 'backend.projects.form.music_video',
                'required' => false,
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'property_path' => 'isMusicVideo',
            ])
            ->add('onlineContent', CheckboxType::class, [
                'label'    => 'backend.projects.form.online_content',
                'required' => false,
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'property_path' => 'isOnlineContent',
            ])
            ->add('shooting', CheckboxType::class, [
                'label'    => 'backend.projects.form.shooting',
                'required' => false,
                'row_attr' => ['data-show-when' => 'type:rodaje'],
                'property_path' => 'isShooting',
            ])

            // ====== Campos EVENTO ======
            ->add('eventTypePrimary', ChoiceType::class, [
                'label'       => 'backend.projects.form.event_type_primary.label',
                'required'    => false,
                'choices'     => $eventTypes,
                'placeholder' => 'backend.common.placeholder',
                'choice_translation_domain' => 'messages',
                'row_attr'    => ['data-show-when' => 'type:evento'],
            ])
            ->add('eventModality', ChoiceType::class, [
                'label'       => 'backend.projects.form.event_modality.label',
                'required'    => false,
                'choices'     => $modalities,
                'placeholder' => 'backend.common.placeholder',
                'choice_translation_domain' => 'messages',
                'row_attr'    => ['data-show-when' => 'type:evento'],
            ])
            ->add('eventAttendeesType', ChoiceType::class, [
                'label'       => 'backend.projects.form.event_attendees_type.label',
                'required'    => false,
                'choices'     => $assistants,
                'placeholder' => 'backend.common.placeholder',
                'choice_translation_domain' => 'messages',
                'row_attr'    => ['data-show-when' => 'type:evento'],
            ])
            ->add('eventAttendeesCount', IntegerType::class, [
                'label'       => 'backend.projects.form.event_attendees_count.label',
                'required'    => false,
                'empty_data'  => '',
                'attr'        => [
                    'min'  => 0,
                    'step' => 1,
                    'class' => 'form-control',
                ],
                'row_attr'    => ['data-show-when' => 'type:evento'],
            ])

            // ====== Campos “confusos” (texto libre) ======
            ->add('medio', TextType::class, [
                'label'    => 'backend.projects.form.common.medio',
                'required' => false,
            ])
            ->add('presupuesto', TextType::class, [
                'label'    => 'backend.projects.form.common.presupuesto',
                'required' => false,
            ])
            ->add('cine', TextType::class, [
                'label'    => 'backend.projects.form.common.cine',
                'required' => false,
            ])
            ->add('fechas', TextType::class, [
                'label'    => 'backend.projects.form.common.fechas',
                'required' => false,
            ])
            ->add('tvField', TextType::class, [
                'label'    => 'backend.projects.form.common.tv',
                'required' => false,
            ])
            ->add('plataformasStreaming', TextType::class, [
                'label'    => 'backend.projects.form.common.plataformas_streaming',
                'required' => false,
            ])
            ->add('agencia', TextType::class, [
                'label'    => 'backend.projects.form.common.agencia',
                'required' => false,
            ])
            ->add('internet', TextType::class, [
                'label'    => 'backend.projects.form.common.internet',
                'required' => false,
            ])
            ->add('redesSociales', TextType::class, [
                'label'    => 'backend.projects.form.common.redes_sociales',
                'required' => false,
            ])
            ->add('fotografia', TextType::class, [
                'label'    => 'backend.projects.form.common.fotografia',
                'required' => false,
            ])
            ->add('radio', TextType::class, [
                'label'    => 'backend.projects.form.common.radio',
                'required' => false,
            ])
            ->add('episodios', TextType::class, [
                'label'    => 'backend.projects.form.common.episodios',
                'required' => false,
            ])
            ->add('duracionEpisodio', TextType::class, [
                'label'    => 'backend.projects.form.common.duracion_episodio',
                'required' => false,
            ])
            ->add('productora', TextType::class, [
                'label'    => 'backend.projects.form.common.productora',
                'required' => false,
            ])

            // ====== Fases ======
            ->add('phaseDates', CollectionType::class, [
                'entry_type'    => ProjectPhaseDateType::class,
                'entry_options' => [
                    'label' => false,
                    'attr'  => ['class' => 'phase-entry-wrapper'],
                ],
                'allow_add'    => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label'        => false,
                'prototype'    => true,
                'prototype_name' => '__phase__',
                'attr' => [
                    'data-project-target' => 'list',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Project::class,
            'show_commercial_tier' => false,
            'commercial_tier_value' => 'basic',
        ]);
    }
}
