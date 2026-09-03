<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\ImpactArea;
use App\Entity\Protocol;
use App\Entity\Department;
use App\Entity\Ods;
use App\Entity\EsG;
use App\Entity\Scope;
use App\Entity\CategoryGhg;
use App\Entity\VerificationSource;
use App\Entity\MeasureBlock;
use App\Form\AuxiliaryType;
use App\Repository\MeasureBlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation as GedmoTranslation;

#[Route('/admin/auxiliary', name: 'admin_auxiliary_')]
class AuxiliaryController extends AbstractController
{
    public function __construct(private TranslatorInterface $translator) {}

    private const ENTITY_MAP = [
        'category'     => Category::class,
        'protocol'     => Protocol::class,
        'department'   => Department::class,
        'ods'          => Ods::class,
        'esg'          => EsG::class,
        'scope'        => Scope::class,
        'category_ghg' => CategoryGhg::class,
        'impact_area'  => ImpactArea::class,
        'verification_source' => VerificationSource::class,
        'measure_block' => MeasureBlock::class,
    ];

    // Claves de traducción (no textos crudos)
    private const ENTITY_NAME = [
        'category'     => 'backend.aux.entity.category',
        'protocol'     => 'backend.aux.entity.protocol',
        'department'   => 'backend.aux.entity.department',
        'ods'          => 'backend.aux.entity.ods',
        'esg'          => 'backend.aux.entity.esg',
        'scope'        => 'backend.aux.entity.scope',
        'category_ghg' => 'backend.aux.entity.category_ghg',
        'impact_area'  => 'backend.aux.entity.impact_area',
        'verification_source' => 'backend.aux.entity.verification_source',
        'measure_block' => 'backend.aux.entity.measure_block',
    ];

    private const TRANSLATABLE_FIELDS = [
        // type => campos traducibles
        'ods'          => ['name','description'],
        'esg'          => ['name','description'],
        'category_ghg' => ['name','description'],
        'impact_area'  => ['name'],
        'verification_source' => ['name'],
        'department'   => ['name'],
        'scope'        => ['name'],
        'protocol'     => ['name'],
        'category'     => ['name'], // si la dejas bloqueada en edit, no aplicará
        'measure_block' => [],
    ];

    private const LOCALES = ['es','en']; // ajusta a lo que uses
    private const DEFAULT_LOCALE = 'es';

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        return $this->render('admin/auxiliary/index.html.twig');
    }

    #[Route('/{type}', name: 'list')]
    public function list(string $type, EntityManagerInterface $em): Response
    {
        if (!array_key_exists($type, self::ENTITY_MAP)) {
            throw $this->createNotFoundException($this->trans('backend.aux.errors.invalid_type'));
        }

        $repoClass = self::ENTITY_MAP[$type];
        $repo = $em->getRepository($repoClass);

        if ($type === 'measure_block') {
            $items = $repo->createQueryBuilder('b')
                ->leftJoin('b.protocol', 'p')
                ->addSelect('p')
                ->orderBy('p.name', 'ASC')
                ->addOrderBy('b.sortOrder', 'ASC')
                ->addOrderBy('b.name', 'ASC')
                ->getQuery()
                ->getResult();
        } elseif (in_array($type, ['category', 'department'], true)) {
            $items = $repo->createQueryBuilder('i')
                ->orderBy('i.sortOrder', 'ASC')
                ->addOrderBy('i.name', 'ASC')
                ->getQuery()
                ->getResult();
        } elseif (in_array($type, ['impact_area', 'verification_source'], true)) {
            $items = $repo->createQueryBuilder('i')
                ->orderBy('i.sortOrder', 'ASC')
                ->addOrderBy('i.name', 'ASC')
                ->getQuery()
                ->getResult();
        } else {
            $items = $repo->findBy([], ['name' => 'ASC']);
        }

        return $this->render('admin/auxiliary/list.html.twig', [
            'items' => $items,
            'type'  => $type,
            'name'  => self::ENTITY_NAME[$type] ?? ucfirst($type),
        ]);
    }

    #[Route('/{type}/new', name: 'new')]
    public function new(string $type, Request $request, EntityManagerInterface $em, MeasureBlockRepository $measureBlockRepository): Response
    {
        if (!array_key_exists($type, self::ENTITY_MAP)) {
            throw $this->createNotFoundException($this->trans('backend.aux.errors.invalid_type'));
        }

        $class = self::ENTITY_MAP[$type];
        $item = new $class();

        if ($item instanceof Category || $item instanceof Department) {
            $item->setSortOrder($this->nextSortOrder($em, $class));
        }

        $translatableFields = self::TRANSLATABLE_FIELDS[$type] ?? ['name'];

        $form = $this->createForm(AuxiliaryType::class, $item, [
            'data_class'          => get_class($item),
            'auxiliary_type'      => $type,
            'locales'             => self::LOCALES,
            'default_locale'      => self::DEFAULT_LOCALE,
            'translatable_fields' => $translatableFields,
            'translations'        => [], // no hay todavía
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($type === 'measure_block' && $item instanceof MeasureBlock && !$item->hasScreeningQuestion()) {
                $item->setScreeningQuestion(null);
            }

            if ($type === 'measure_block' && $item instanceof MeasureBlock) {
                $existing = $measureBlockRepository->findOneByProtocolAndCode($item->getProtocol(), $item->getCode())
                    ?? $measureBlockRepository->findEquivalentByProtocol($item->getProtocol(), $item->getCode());
                if ($existing instanceof MeasureBlock) {
                    $form->get('code')->addError(new \Symfony\Component\Form\FormError($this->translator->trans('backend.aux.errors.measure_block_code_exists')));

                    return $this->render('admin/auxiliary/form.html.twig', [
                        'form' => $form->createView(),
                        'type' => $type,
                        'edit' => false,
                        'name' => self::ENTITY_NAME[$type] ?? ucfirst($type),
                        'locales' => self::LOCALES,
                        'default_locale' => self::DEFAULT_LOCALE,
                        'translatableFields' => $translatableFields,
                    ]);
                }
            }

            $em->persist($item);
            $em->flush(); // necesitamos ID para traducir

            /** @var TranslationRepository $transRepo */
            $transRepo = $em->getRepository(\Gedmo\Translatable\Entity\Translation::class);

            foreach (self::LOCALES as $loc) {
                if ($loc === self::DEFAULT_LOCALE) continue;

                foreach ($translatableFields as $field) {
                    $formField = $field . '_' . $loc; // p.ej. name_en
                    if (!$form->has($formField)) continue;

                    $value = $form->get($formField)->getData();
                    if ($value !== null && $value !== '') {
                        $transRepo->translate($item, $field, $loc, $value);
                    }
                }
            }

            $em->flush();

            $this->addFlash('success', 'backend.aux.flash.created');
            return $this->redirectToRoute('admin_auxiliary_list', ['type' => $type]);
        }

        return $this->render('admin/auxiliary/form.html.twig', [
            'form' => $form->createView(),
            'type' => $type,
            'edit' => false,
            'name' => self::ENTITY_NAME[$type] ?? ucfirst($type),
            'locales' => self::LOCALES,
            'default_locale' => self::DEFAULT_LOCALE,
            'translatableFields' => $translatableFields,
        ]);
    }

    private function nextSortOrder(EntityManagerInterface $em, string $class): int
    {
        $max = (int) $em->createQueryBuilder()
            ->select('COALESCE(MAX(i.sortOrder), 0)')
            ->from($class, 'i')
            ->getQuery()
            ->getSingleScalarResult();

        return $max + 10;
    }

    #[Route('/{type}/{id}/edit', name: 'edit')]
    public function edit(
        string $type,
        int $id,
        Request $request,
        EntityManagerInterface $em,
        MeasureBlockRepository $measureBlockRepository,
        TranslatableListener $translatableListener
    ): Response {
        if (!array_key_exists($type, self::ENTITY_MAP)) {
            throw $this->createNotFoundException($this->trans('backend.aux.errors.invalid_type'));
        }

        $repoClass = self::ENTITY_MAP[$type];

        // 1) Forzamos ES para CARGAR en español
        $originalLocale = $request->getLocale(); // p.ej. "en"
        $translatableListener->setTranslatableLocale('es');
        $translatableListener->setTranslationFallback(false);

        $item = $em->getRepository($repoClass)->find($id);
        if (!$item) {
            throw $this->createNotFoundException($this->trans('backend.aux.errors.not_found'));
        }
        $em->refresh($item); // aseguramos valores en ES

        /** @var \Gedmo\Translatable\Entity\Repository\TranslationRepository $tr */
        $tr = $em->getRepository(\Gedmo\Translatable\Entity\Translation::class);
        $existing = $tr->findTranslations($item);

        $formOptions = [
            'data_class'          => get_class($item),
            'auxiliary_type'      => $type,
            'locales'             => ['es','en'],
            'default_locale'      => 'es',
            'translatable_fields' => in_array($type, ['ods','esg','category_ghg'], true) ? ['name','description'] : ($type === 'measure_block' ? [] : ['name']),
            'translations'        => $existing,
            'category_read_only_fields' => $type === 'category',
        ];

        $form = $this->createForm(AuxiliaryType::class, $item, $formOptions);
        $form->handleRequest($request);

        // ⚠️ OJO: NO restauramos aún la locale aquí.
        // La restauraremos DESPUÉS de guardar para que ES se persista en columnas base.

        if ($form->isSubmitted() && $form->isValid()) {
            if ($type === 'measure_block' && $item instanceof MeasureBlock && !$item->hasScreeningQuestion()) {
                $item->setScreeningQuestion(null);
            }

            if ($type === 'measure_block' && $item instanceof MeasureBlock) {
                $existing = $measureBlockRepository->findOneByProtocolAndCode($item->getProtocol(), $item->getCode())
                    ?? $measureBlockRepository->findEquivalentByProtocol($item->getProtocol(), $item->getCode());
                if ($existing instanceof MeasureBlock && $existing->getId() !== $item->getId()) {
                    $form->get('code')->addError(new \Symfony\Component\Form\FormError($this->translator->trans('backend.aux.errors.measure_block_code_exists')));

                    return $this->render('admin/auxiliary/form.html.twig', [
                        'form' => $form->createView(),
                        'type' => $type,
                        'edit' => true,
                        'name' => self::ENTITY_NAME[$type] ?? ucfirst($type),
                        'locales' => $formOptions['locales'],
                        'default_locale' => $formOptions['default_locale'],
                        'translatableFields' => $formOptions['translatable_fields'],
                    ]);
                }
            }

            // 2) Guardar campos ES (mapeados) → forzar ES antes de persistir/flush
            $translatableListener->setTranslatableLocale('es');
            $translatableListener->setTranslationFallback(false);

            // 3) Aplicar traducciones EN desde los unmapped
            $locales = ['en'];
            $fields  = $formOptions['translatable_fields'];

            foreach ($locales as $loc) {
                foreach ($fields as $f) {
                    $fname = $f . '_' . $loc; // p.ej. name_en
                    if ($form->has($fname)) {
                        $val = $form->get($fname)->getData();
                        if ($val !== null && $val !== '') {
                            $tr->translate($item, $f, $loc, $val);
                        } else {
                            // si quieres limpiar traducción vacía
                            $tr->translate($item, $f, $loc, null);
                        }
                    }
                }
            }

            $em->persist($item);
            $em->flush();

            // 4) Restaurar locale original para el resto de la petición/respuesta
            $translatableListener->setTranslatableLocale($originalLocale);
            $translatableListener->setTranslationFallback(true);

            $this->addFlash('success', 'backend.aux.flash.updated');
            return $this->redirectToRoute('admin_auxiliary_list', ['type' => $type]);
        }

        // Si no se ha enviado/validado, restauramos antes de renderizar
        $translatableListener->setTranslatableLocale($originalLocale);
        $translatableListener->setTranslationFallback(true);

        return $this->render('admin/auxiliary/form.html.twig', [
            'form' => $form->createView(),
            'type' => $type,
            'edit' => true,
            'name' => self::ENTITY_NAME[$type] ?? ucfirst($type),
            'locales' => $formOptions['locales'],
            'default_locale' => $formOptions['default_locale'],
            'translatableFields' => $formOptions['translatable_fields'],
        ]);
    }

    #[Route('/{type}/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(string $type, int $id, Request $request, EntityManagerInterface $em): Response
    {
        if (!array_key_exists($type, self::ENTITY_MAP)) {
            throw $this->createNotFoundException($this->trans('backend.aux.errors.invalid_type'));
        }

        $repository = $em->getRepository(self::ENTITY_MAP[$type]);
        $item = $repository->find($id);

        if (!$item) {
            throw $this->createNotFoundException($this->trans('backend.aux.errors.not_found'));
        }

        if ($type === 'category') {
            $this->addFlash('danger', 'backend.aux.errors.category_locked');
            return $this->redirectToRoute('admin_auxiliary_list', ['type' => $type]);
        }

        if ($this->isCsrfTokenValid('delete_' . $id, $request->request->get('_token'))) {
            try {
                $em->remove($item);
                $em->flush();
                $this->addFlash('success', 'backend.aux.flash.deleted');
            } catch (ForeignKeyConstraintViolationException $e) {
                $this->addFlash('danger', 'backend.aux.errors.in_use');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'backend.aux.errors.delete_failed');
            }
        } else {
            $this->addFlash('danger', 'backend.common.csrf_invalid');
        }

        return $this->redirectToRoute('admin_auxiliary_list', ['type' => $type]);
    }

    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'messages');
    }
}
