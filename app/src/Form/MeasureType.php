<?php
// src/Form/MeasureType.php
namespace App\Form;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Protocol;
use App\Entity\Category;
use App\Entity\Department;
use App\Repository\DepartmentRepository;
use App\Entity\Ods;
use App\Entity\EsG;
use App\Entity\Scope;
use App\Entity\CategoryGhg;
use App\Entity\ImpactArea;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use App\Repository\MeasureBlockRepository;
use App\Repository\ProtocolRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MeasureType extends AbstractType
{
    public function __construct(
        private readonly ProtocolRepository $protocolRepository,
        private readonly MeasureBlockRepository $measureBlockRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $projectType          = $options['projectType'] ?? null;
        $locales              = $options['locales'] ?? ['es','en'];
        $defaultLocale        = $options['default_locale'] ?? 'es';
        $translatableFields   = $options['translatable_fields'] ?? ['name','nameReview','questionText','gamificationMessage','description','implementation','departmentActionText'];
        $existingTranslations = $options['translations'] ?? [];
        $measure              = $builder->getData();
        $verificationLinks    = $measure instanceof Measure ? $measure->getResolvedVerificationSourceLinks() : [];
        $verificationLink1    = $verificationLinks[0] ?? null;
        $verificationLink2    = $verificationLinks[1] ?? null;
        $verificationLink3    = $verificationLinks[2] ?? null;
        $measureBlock = $measure instanceof Measure ? $measure->getMeasureBlock() : null;

        $addMeasureBlockField = function (FormInterface $form, ?Protocol $protocol) use ($measureBlock): void {
            $form->add('measureBlock', EntityType::class, [
                'class' => MeasureBlock::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.measure_block',
                'required' => false,
                'query_builder' => function (MeasureBlockRepository $repo) use ($protocol) {
                    $qb = $repo->createQueryBuilder('b')
                        ->andWhere('b.active = true')
                        ->orderBy('b.sortOrder', 'ASC')
                        ->addOrderBy('b.name', 'ASC');

                    if ($protocol instanceof Protocol) {
                        $qb->andWhere('b.protocol = :protocol')
                            ->setParameter('protocol', $protocol);
                    } else {
                        $qb->andWhere('1 = 0');
                    }

                    return $qb;
                },
                'data' => ($measureBlock instanceof MeasureBlock && $protocol && $measureBlock->getProtocol()?->getId() === $protocol->getId()) ? $measureBlock : null,
            ]);
        };

        $builder->add('sortOrder', IntegerType::class, [
            'label' => 'backend.measures.form.sort_order',
            'required' => false,
            'empty_data' => '0',
            'attr' => [
                'min' => 0,
            ],
        ]);

        // ===== Campos translatables base (locale por defecto, mapeados) =====
        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.measures.form.name',
            ])
            ->add('nameReview', TextType::class, [
                'label'    => 'backend.measures.form.name_review',
                'required' => false,
                'attr'     => ['placeholder' => 'backend.measures.form.name_review_ph'],
            ])
            ->add('questionText', TextareaType::class, [
                'label'    => 'backend.measures.form.question_text',
                'required' => false,
                'attr'     => ['rows' => 4],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'backend.measures.form.description',
                'required' => false,
                'attr'     => ['rows' => 4],
            ])
            ->add('gamificationMessage', TextareaType::class, [
                'label'    => 'backend.measures.form.gamification_message',
                'required' => false,
                'attr'     => ['rows' => 4],
            ])
            ->add('departmentActionText', TextareaType::class, [
                'label'    => 'backend.measures.form.department_action_text',
                'required' => false,
                'attr'     => ['rows' => 4],
            ]);

        // ===== Campos por-locale (unmapped) para tabs =====
        foreach ($locales as $loc) {
            if ($loc === $defaultLocale) {
                continue; // el idioma por defecto se edita en los campos mapeados
            }

            // name_{loc}
            if (in_array('name', $translatableFields, true)) {
                $builder->add('name_' . $loc, TextType::class, [
                    'label'    => 'backend.measures.form.name',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['name'] ?? null,
                    'attr'     => ['class' => 'form-control'],
                ]);
            }

            // nameReview_{loc}
            if (in_array('nameReview', $translatableFields, true)) {
                $builder->add('nameReview_' . $loc, TextType::class, [
                    'label'    => 'backend.measures.form.name_review',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['nameReview'] ?? null,
                    'attr'     => [
                        'class'       => 'form-control',
                        'placeholder' => 'backend.measures.form.name_review_ph',
                    ],
                ]);
            }

            // questionText_{loc}
            if (in_array('questionText', $translatableFields, true)) {
                $builder->add('questionText_' . $loc, TextareaType::class, [
                    'label'    => 'backend.measures.form.question_text',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['questionText'] ?? null,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
            }

            // description_{loc}
            if (in_array('description', $translatableFields, true)) {
                $builder->add('description_' . $loc, TextareaType::class, [
                    'label'    => 'backend.measures.form.description',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['description'] ?? null,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
            }

            if (in_array('gamificationMessage', $translatableFields, true)) {
                $builder->add('gamificationMessage_' . $loc, TextareaType::class, [
                    'label'    => 'backend.measures.form.gamification_message',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['gamificationMessage'] ?? null,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
            }

            // implementation_{loc}
            if (in_array('implementation', $translatableFields, true)) {
                $builder->add('implementation_' . $loc, TextareaType::class, [
                    'label'    => 'backend.measures.form.implementation',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['implementation'] ?? null,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
            }

            // departmentActionText_{loc}
            if (in_array('departmentActionText', $translatableFields, true)) {
                $builder->add('departmentActionText_' . $loc, TextareaType::class, [
                    'label'    => 'backend.measures.form.department_action_text',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['departmentActionText'] ?? null,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
            }

        }

        // ===== Resto de campos =====
        $builder
            ->add('protocol', EntityType::class, [
                'class' => Protocol::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.protocol',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'name',
                'query_builder' => fn($r) => $r->createQueryBuilder('c')->orderBy('c.sortOrder', 'ASC')->addOrderBy('c.name', 'ASC'),
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.category',
                'required' => false,
            ])
            ->add('departments', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'displayName',
                'query_builder' => fn(DepartmentRepository $r) => $r->qbForProjectType($projectType),
                'label' => 'backend.measures.form.departments',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('odsItems', EntityType::class, [
                'class' => Ods::class,
                'choice_label' => 'name',
                'label' => 'backend.measures.form.ods_items',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('esg', EntityType::class, [
                'class' => EsG::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.esg',
                'required' => false,
            ])
            ->add('scope', EntityType::class, [
                'class' => Scope::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.scope',
                'required' => false,
            ])
            ->add('categoryGhg', EntityType::class, [
                'class' => CategoryGhg::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.category_ghg',
                'required' => false,
            ])
            ->add('impactAreas', EntityType::class, [
                'class' => ImpactArea::class,
                'choice_label' => 'name',
                'label' => 'backend.measures.form.impact_areas',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('tripleBalanceAxes', EntityType::class, [
                'class' => TripleBalanceAxis::class,
                'choice_label' => 'name',
                'label' => 'backend.measures.form.triple_balance_axes',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('verificationSourcePriority1', EntityType::class, [
                'class' => VerificationSource::class,
                'choice_label' => 'name',
                'query_builder' => fn($r) => $r->createQueryBuilder('v')->orderBy('v.sortOrder', 'ASC')->addOrderBy('v.code', 'ASC'),
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.verification_source_priority_1',
                'required' => false,
                'mapped' => false,
                'data' => $verificationLink1?->getVerificationSource(),
            ])
            ->add('verificationSourcePriority2', EntityType::class, [
                'class' => VerificationSource::class,
                'choice_label' => 'name',
                'query_builder' => fn($r) => $r->createQueryBuilder('v')->orderBy('v.sortOrder', 'ASC')->addOrderBy('v.code', 'ASC'),
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.verification_source_priority_2',
                'required' => false,
                'mapped' => false,
                'data' => $verificationLink2?->getVerificationSource(),
            ])
            ->add('verificationSourcePriority3', EntityType::class, [
                'class' => VerificationSource::class,
                'choice_label' => 'name',
                'query_builder' => fn($r) => $r->createQueryBuilder('v')->orderBy('v.sortOrder', 'ASC')->addOrderBy('v.code', 'ASC'),
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.verification_source_priority_3',
                'required' => false,
                'mapped' => false,
                'data' => $verificationLink3?->getVerificationSource(),
            ])
            ->add('implementation', TextareaType::class, [
                'label' => 'backend.measures.form.implementation',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('mandatory', CheckboxType::class, [
                'label'    => 'backend.measures.form.mandatory',
                'required' => false,
                'help'     => 'backend.measures.form.mandatory_help',
            ])
            ->add('score', IntegerType::class, [
                'label'    => 'backend.measures.form.score',
                'required' => false,
                'attr'     => ['min' => 1, 'max' => 5, 'step' => 1, 'placeholder' => '1–5'],
                'help'     => 'backend.measures.form.score_help',
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($addMeasureBlockField): void {
            $measure = $event->getData();
            $protocol = $measure instanceof Measure ? $measure->getProtocol() : null;

            $addMeasureBlockField($event->getForm(), $protocol);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($addMeasureBlockField): void {
            $data = $event->getData();
            $protocolId = isset($data['protocol']) ? (int) $data['protocol'] : 0;
            $protocol = $protocolId > 0 ? $this->protocolRepository->find($protocolId) : null;

            $addMeasureBlockField($event->getForm(), $protocol instanceof Protocol ? $protocol : null);
        });

        // Para tabs en la vista
        $builder->setAttribute('locales', $locales);
        $builder->setAttribute('default_locale', $defaultLocale);
        $builder->setAttribute('translatable_fields', $translatableFields);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => Measure::class,
            'projectType'         => null,       // 'rodaje' | 'evento' | null
            'locales'             => ['es','en'],
            'default_locale'      => 'es',
            'translatable_fields' => ['name','nameReview','questionText','gamificationMessage','description','implementation','departmentActionText'],
            'translations'        => [],
        ]);
    }
}
