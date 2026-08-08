<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\EmissionActivity;
use App\Entity\EmissionRecord;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmissionRecordType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Category|null $category */
        $category   = $options['category'] ?? null;
        $categoryId = $options['categoryId'] ?? null;

        // Permitir pasar categoryId en lugar de la entidad
        if (!$category && $categoryId) {
            $category = $this->em->getRepository(Category::class)->find((int)$categoryId);
        }

        /** @var EmissionRecord|null $record */
        $record = $options['data'] ?? null;

        $registeredYear = $record?->getRegisteredAt()?->format('Y');
        $registeredYear = $registeredYear ? (int)$registeredYear : (int) date('Y');

        // Fuente: si hay proyecto, la suya; si no, MITECO
        $sourceName = $record?->getProject()?->getEmissionSourceName() ?: 'MITECO';

        // Año más reciente disponible para esa categoría y fuente, sin pasarse del año del registro
        $maxYear = $registeredYear;
        if ($category) {
            $maxYearQb = $this->em->getRepository(EmissionActivity::class)
                ->createQueryBuilder('a')
                ->select('MAX(s.year)')
                ->join('a.emissionSource', 's')
                ->where('a.category = :category')
                ->andWhere('s.name = :sname')
                ->andWhere('s.year <= :year')
                ->setParameter('category', $category)
                ->setParameter('sname', $sourceName)
                ->setParameter('year', $registeredYear);

            $maxYear = (int) ($maxYearQb->getQuery()->getSingleScalarResult() ?? $registeredYear);
        }

        $builder
            ->add('registeredAt', DateType::class, [
                'label' => 'backend.emission.form.registered_at',
                'translation_domain' => 'messages',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('activity', EntityType::class, [
                'class' => EmissionActivity::class,
                'choice_label' => fn(EmissionActivity $a) => $a->getName(), // Gedmo ya lo saca en el locale visible
                'choice_attr'  => fn(EmissionActivity $a) => ['data-unit' => $a->getUnit()],
                'placeholder'  => 'backend.emission.form.activity_placeholder',
                'required' => true,
                'label' => 'backend.emission.form.activity',
                'translation_domain' => 'messages',
                'attr' => [
                    'class' => 'form-select',
                    'required' => true,
                    'data-emission-target' => 'activity',
                ],
                'query_builder' => function (EntityRepository $er) use ($category, $maxYear, $sourceName) {
                    $qb = $er->createQueryBuilder('a')
                        ->join('a.emissionSource', 's')
                        ->orderBy('a.name', 'ASC');

                    // Si falta category, devolvemos 0 resultados
                    if (!$category) {
                        return $qb->where('1 = 0');
                    }

                    return $qb
                        ->where('a.category = :category')
                        ->andWhere('(a.subcategory IS NULL OR a.subcategory != :woodSubcategory)')
                        ->andWhere('s.name = :sname')
                        ->andWhere('s.year = :year')
                        ->setParameter('category', $category)
                        ->setParameter('woodSubcategory', 'madera')
                        ->setParameter('sname', $sourceName)
                        ->setParameter('year', $maxYear);
                },
            ])
            ->add('amount', NumberType::class, [
                'label' => 'backend.emission.form.amount',
                'translation_domain' => 'messages',
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'step' => 'any',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmissionRecord::class,
            'category'   => null,    // puedes seguir pasando la ENTIDAD
            'categoryId' => null,    // o solo el ID (se resolverá dentro)
        ]);

        $resolver->setAllowedTypes('category',   ['null', Category::class]);
        $resolver->setAllowedTypes('categoryId', ['null', 'int', 'string']);
    }
}
