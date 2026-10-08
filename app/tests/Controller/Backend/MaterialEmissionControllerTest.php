<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\MaterialEmissionController;
use App\DataFixtures\MaterialEmissionFactorFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\CategoryRepository;
use App\Repository\EmissionFactorRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\Material\MaterialAmountNormalizer;
use App\Service\Emission\Material\MaterialEmissionCalculator;
use App\Service\Emission\Material\MaterialEmissionRecordService;
use App\Service\Emission\Material\MaterialEmissionRequestMapper;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Material\MaterialFactorResolver;
use App\Service\Emission\Material\MaterialUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class MaterialEmissionControllerTest extends KernelTestCase
{
    public function testRendersModernMaterialFormWithBackendCatalog(): void
    {
        $context = $this->context();
        $ignored = null;
        $response = $this->controller()->create(
            $this->request('GET'),
            $context['active'],
            $context['categories'],
            $context['projects'],
            new MaterialEmissionRequestMapper(new MaterialUiCatalog()),
            $this->recordService(0, $ignored),
            new MaterialUiCatalog(),
            $this->storage(),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringContainsString('data-controller="material-v1-form"', $content);
        self::assertStringContainsString('Materiales y Productos', $content);
        self::assertStringNotContainsString('densidad_kg_m3', $content);
        self::assertSame(1, preg_match('/data-material-v1-form-catalog-value="([^"]+)"/', $content, $matches));
        $catalog = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(14, $catalog['families']);
        self::assertContains('Pilas y baterías', array_column($catalog['families'], 'label'));
        self::assertSame(['FSC', 'PEFC', 'Sin sello'], $catalog['sustainabilitySeals']['wood']);
        self::assertSame(['FSC', 'PEFC', 'Sin sello', 'Desconocido'], $catalog['sustainabilitySeals']['paper']);
        self::assertSame(['FSC', 'PEFC', 'Sin sello', 'Desconocido'], $catalog['sustainabilitySeals']['cardboard']);
        self::assertSame([
            'Cartón compacto / no corrugado',
            'Cartón corrugado de 2 capas',
            'Cartón corrugado de 3 capas',
            'Cartón corrugado de 5 capas',
            'Cartón corrugado de 7 capas',
            'Otro',
            'Desconocido',
        ], $catalog['cardboardStructures']);
        self::assertSame(['Acero', 'Hierro', 'Aluminio', 'Cobre', 'Acero inoxidable', 'Latón', 'Zinc', 'Otro metal', 'Desconocido'], $catalog['metalMaterials']);
        self::assertSame(['Perfiles', 'Rieles', 'Tubos', 'Chapas / placas', 'Barras / varillas', 'Mallas / rejillas', 'Herrajes / piezas', 'Estructura mixta', 'Otra forma', 'Desconocido'], $catalog['metalForms']);
        self::assertSame([
            'Madera maciza',
            'Táblex',
            'DM o MDF',
            'Aglomerada',
            'Contrachapada o Laminada (Plywood)',
            'OSB',
            'Desconocida',
        ], $catalog['woodSelections']);
        self::assertSame(MaterialUiCatalog::ORIGIN_PURCHASED_PRESENTATION, $catalog['purchasedOriginPresentationValue']);
        $plasticFamily = current(array_filter($catalog['families'], static fn (array $family): bool => 'plastic' === $family['value']));
        self::assertSame([
            ['Film, bolsas y láminas — plástico flexible', 'Película de plástico promedio'],
            ['Envases y piezas rígidas — plástico rígido', 'Plástico rígido promedio'],
            ['HDPE / PEAD — garrafas, bidones y cajas rígidas', 'Polietileno de alta densidad (HDPE/PEAD)'],
            ['LDPE / PEBD — bolsas, film y láminas flexibles', 'Polietileno de baja densidad (LPDE/PEBD y LLPDE/PELBD)'],
            ['PET — botellas y envases transparentes', 'Tereftalato de polietileno (PET)'],
            ['PP — tapas, cajas, recipientes y piezas', 'Polipropileno (PP)'],
            ['PS — poliestireno, bandejas y espuma', 'Poliestireno (PS)'],
            ['PVC — tubos, perfiles y láminas', 'Policloruro de vinilo (PVC)'],
            ['Plástico mixto / promedio', 'Plástico promedio'],
            ['Desconocido', 'Plástico promedio'],
        ], array_map(
            static fn (array $activity): array => [$activity['label'], $activity['value']],
            $plasticFamily['activities'],
        ));
        self::assertCount(9, array_unique(array_column($plasticFamily['activities'], 'value')));
        foreach ($plasticFamily['activities'] as $activity) {
            self::assertSame('', $activity['subproducts'][0]['value']);
            self::assertContains('Reutilizado', $activity['subproducts'][0]['origins']);
        }
        foreach (['paint', 'varnish', 'solvent'] as $familyValue) {
            $liquidFamily = current(array_filter(
                $catalog['families'],
                static fn (array $family): bool => $familyValue === $family['value'],
            ));
            self::assertContains('', $liquidFamily['activities'][0]['subproducts'][0]['origins']);
            self::assertContains('Reutilizado', $liquidFamily['activities'][0]['subproducts'][0]['origins']);
        }
        self::assertSame(1, preg_match('/data-material-v1-form-i18n-value="([^"]+)"/', $content, $i18nMatches));
        $i18n = json_decode(html_entity_decode($i18nMatches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Comprado', $i18n['purchasedOrigin']);
        self::assertSame('Film, bolsas y láminas — plástico flexible', $i18n['plasticActivities']['flexible_film']);
        $translator = self::getContainer()->get('translator');
        self::assertSame('Purchased', $translator->trans('backend.emission.material_v1.origins.purchased', locale: 'en'));
        self::assertSame(array_column($plasticFamily['activities'], 'label'), array_map(
            static fn (array $activity): string => $translator->trans(
                'backend.emission.material_v1.plastic.'.$activity['translationKey'],
                locale: 'es',
            ),
            $plasticFamily['activities'],
        ));
        self::assertSame([
            'Film, bags and sheets — flexible plastic',
            'Rigid containers and parts — rigid plastic',
            'HDPE / PEAD — jerrycans, drums and rigid boxes',
            'LDPE / PEBD — bags, film and flexible sheets',
            'PET — bottles and transparent containers',
            'PP — caps, boxes, containers and parts',
            'PS — polystyrene, trays and foam',
            'PVC — pipes, profiles and sheets',
            'Mixed / average plastic',
            'Unknown',
        ], array_map(
            static fn (array $activity): string => $translator->trans(
                'backend.emission.material_v1.plastic.'.$activity['translationKey'],
                locale: 'en',
            ),
            $plasticFamily['activities'],
        ));
        foreach (['sustainabilitySeal', 'cardboardStructure', 'metalMaterial', 'metalForm', 'clothingGroup'] as $field) {
            self::assertStringContainsString(sprintf('name="%s"', $field), $content);
        }
        $fieldPositions = $this->formFieldPositions($content);
        self::assertTrue($fieldPositions['origin'] < $fieldPositions['sustainabilitySeal']);
        self::assertTrue($fieldPositions['sustainabilitySeal'] < $fieldPositions['paperFormat']);
        self::assertTrue($fieldPositions['paperFormat'] < $fieldPositions['measurementMethod']);
        self::assertTrue($fieldPositions['woodType'] < $fieldPositions['origin']);
        self::assertLessThan(
            strpos($content, 'id="material-origin"'),
            strpos($content, 'id="material-wood-selection"'),
        );
        self::assertTrue($fieldPositions['cardboardStructure'] < $fieldPositions['origin']);
        self::assertTrue($fieldPositions['metalMaterial'] < $fieldPositions['metalForm']);
        self::assertTrue($fieldPositions['metalForm'] < $fieldPositions['origin']);
        self::assertTrue($fieldPositions['measurementMethod'] < $fieldPositions['boardFamily']);
        self::assertTrue($fieldPositions['measurementMethod'] < $fieldPositions['boardThickness']);
        self::assertTrue($fieldPositions['measurementMethod'] < $fieldPositions['cardboardType']);

        self::assertSame([
            'Parte de arriba',
            'Parte de abajo',
            'Vestidos y prendas completas',
            'Calzado',
            'Accesorios',
            'Ropa interior y baño',
            'Otros',
        ], array_column($catalog['clothingGroups'], 'value'));

        $clothingFamily = current(array_filter($catalog['families'], static fn (array $family): bool => 'clothing' === $family['value']));
        $realSubproducts = array_column($clothingFamily['activities'][0]['subproducts'], 'value');
        $groupedSubproducts = [];
        $groupBySubproduct = [];
        foreach ($catalog['clothingGroups'] as $group) {
            foreach ($group['subproducts'] as $subproduct) {
                $groupedSubproducts[] = $subproduct['value'];
                $groupBySubproduct[$subproduct['value']] = $group['value'];
            }
        }
        sort($realSubproducts);
        sort($groupedSubproducts);
        $realSubproducts = array_values(array_filter(
            $realSubproducts,
            static fn (string $subproduct): bool => MaterialUiCatalog::ACTIVITY_CLOTHING !== $subproduct,
        ));
        self::assertCount(59, $groupedSubproducts);
        self::assertNotContains(MaterialUiCatalog::ACTIVITY_CLOTHING, $groupedSubproducts);
        self::assertSame($realSubproducts, $groupedSubproducts);
        self::assertCount(count($groupedSubproducts), array_unique($groupedSubproducts));
        self::assertSame('Parte de arriba', $groupBySubproduct['Camiseta manga corta']);
        self::assertSame('Parte de abajo', $groupBySubproduct['Pantalón']);
        self::assertSame('Vestidos y prendas completas', $groupBySubproduct['Vestido']);
        self::assertSame('Calzado', $groupBySubproduct['Zapatillas deportivas']);
        self::assertSame('Accesorios', $groupBySubproduct['Bolso']);
        self::assertSame('Ropa interior y baño', $groupBySubproduct['Sujetador']);
        self::assertSame('Otros', $groupBySubproduct['Cardigan']);
    }

    public function testRequestMapperAndSnapshotPreserveStructuredMetadata(): void
    {
        $mapper = new MaterialEmissionRequestMapper(new MaterialUiCatalog());
        $snapshot = new MaterialEmissionSnapshot();
        $cardboardInput = $mapper->map($this->request('POST', [
            ...$this->basePost(),
            'activity' => MaterialUiCatalog::ACTIVITY_CARDBOARD,
            'origin' => 'Producción de materia prima',
            'measurementMethod' => 'weight',
            'inputQuantity' => '10',
            'inputUnit' => 'kg',
            'sustainabilitySeal' => 'PEFC',
            'cardboardStructure' => 'Cartón corrugado de 5 capas',
        ]));
        $metalInput = $mapper->map($this->request('POST', [
            ...$this->basePost(),
            'activity' => MaterialUiCatalog::ACTIVITY_METAL,
            'origin' => 'Producción de materia prima',
            'measurementMethod' => 'weight',
            'inputQuantity' => '10',
            'inputUnit' => 'kg',
            'metalMaterial' => 'Acero inoxidable',
            'metalForm' => 'Chapas / placas',
        ]));
        $clothingInput = $mapper->map($this->request('POST', $this->clothingPost()));

        self::assertSame('cardboard', $cardboardInput->family);
        self::assertSame('metal', $metalInput->family);
        self::assertSame('PEFC', $cardboardInput->sustainabilitySeal);
        self::assertSame('Cartón corrugado de 5 capas', $cardboardInput->cardboardStructure);
        self::assertSame('Acero inoxidable', $metalInput->metalMaterial);
        self::assertSame('Chapas / placas', $metalInput->metalForm);
        self::assertSame('Parte de arriba', $clothingInput->clothingGroup);

        $storedCardboard = $snapshot->inputToArray($cardboardInput);
        $storedMetal = $snapshot->inputToArray($metalInput);
        $storedClothing = $snapshot->inputToArray($clothingInput);
        self::assertSame('PEFC', $storedCardboard['sustainabilitySeal']);
        self::assertSame('Cartón corrugado de 5 capas', $storedCardboard['cardboardStructure']);
        self::assertSame('Acero inoxidable', $storedMetal['metalMaterial']);
        self::assertSame('Chapas / placas', $storedMetal['metalForm']);
        self::assertSame('Parte de arriba', $storedClothing['clothingGroup']);

        $decodedCardboard = $snapshot->decodeInput($snapshot->encode($cardboardInput, $this->calculator()->calculate($cardboardInput)));
        $decodedMetal = $snapshot->decodeInput($snapshot->encode($metalInput, $this->calculator()->calculate($metalInput)));
        $withGroup = $this->calculator()->calculate($clothingInput);
        $decodedClothing = $snapshot->decodeInput($snapshot->encode($clothingInput, $withGroup));
        self::assertSame('PEFC', $decodedCardboard->sustainabilitySeal);
        self::assertSame('Cartón corrugado de 5 capas', $decodedCardboard->cardboardStructure);
        self::assertSame('Acero inoxidable', $decodedMetal->metalMaterial);
        self::assertSame('Chapas / placas', $decodedMetal->metalForm);
        self::assertSame('Parte de arriba', $decodedClothing->clothingGroup);

        $clothingWithoutGroup = $mapper->map($this->request('POST', array_diff_key($this->clothingPost(), ['clothingGroup' => true])));
        $withoutGroup = $this->calculator()->calculate($clothingWithoutGroup);
        self::assertSame($withGroup->normalizedAmount, $withoutGroup->normalizedAmount);
        self::assertSame($withGroup->emissionKgCo2e, $withoutGroup->emissionKgCo2e);
    }

    public function testSnapshotDecodesLegacyInputWithoutNewStructuredMetadata(): void
    {
        $input = (new MaterialEmissionRequestMapper(new MaterialUiCatalog()))->map($this->request('POST', $this->woodPost()));
        $snapshot = new MaterialEmissionSnapshot();
        $data = json_decode(
            $snapshot->encode($input, $this->calculator()->calculate($input)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        unset(
            $data['input']['sustainabilitySeal'],
            $data['input']['cardboardStructure'],
            $data['input']['metalMaterial'],
            $data['input']['metalForm'],
            $data['input']['clothingGroup'],
        );

        $decoded = $snapshot->decodeInput(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertNull($decoded->sustainabilitySeal);
        self::assertNull($decoded->cardboardStructure);
        self::assertNull($decoded->metalMaterial);
        self::assertNull($decoded->metalForm);
        self::assertNull($decoded->clothingGroup);
        self::assertSame(MaterialUiCatalog::ACTIVITY_WOOD, $decoded->activity);
        self::assertSame('Producción de materia prima', $decoded->origin);
        self::assertSame('weight', $decoded->measurementMethod);
        self::assertSame('100', $decoded->inputQuantity);
        self::assertSame('wood', $decoded->family);
    }

    public function testPreviewDistinguishesConvertedFactorRuleZeroAndUnavailableFactor(): void
    {
        $context = $this->context();
        $controller = $this->controller();

        $paint = $this->preview($controller, $context['active'], [
            ...$this->basePost(),
            'activity' => MaterialUiCatalog::ACTIVITY_PAINT,
            'subproduct' => 'Pintura con base al agua',
            'origin' => MaterialUiCatalog::ORIGIN_PURCHASED_PRESENTATION,
            'measurementMethod' => 'volume',
            'inputQuantity' => '10',
            'inputUnit' => 'l',
        ]);
        self::assertSame('CALCULATED', $paint['status']);
        self::assertSame('16', $paint['normalizedAmount']);
        self::assertSame('34.45879792', $paint['emissionKgCo2e']);
        self::assertSame('VERSIONED', $paint['factorTraces'][0]['temporalType']);
        self::assertNull($paint['factorTraces'][0]['factorYear']);

        $rule = $this->preview($controller, $context['active'], [
            ...$this->basePost(),
            'activity' => MaterialUiCatalog::ACTIVITY_PAPER,
            'origin' => 'Reutilizado',
            'measurementMethod' => 'weight',
            'inputQuantity' => '12',
            'inputUnit' => 'kg',
        ]);
        self::assertSame('CALCULATED', $rule['status']);
        self::assertSame('0', $rule['emissionKgCo2e']);
        self::assertSame('RULE', $rule['factorTraces'][0]['temporalType']);

        $unavailable = $this->preview($controller, $context['active'], [
            ...$this->basePost(),
            'activity' => 'Plástico promedio',
            'origin' => 'Reutilizado',
            'measurementMethod' => 'weight',
            'inputQuantity' => '10',
            'inputUnit' => 'kg',
        ]);
        self::assertSame('NOT_AUTOMATICALLY_CALCULABLE', $unavailable['status']);
        self::assertNull($unavailable['emissionKgCo2e']);
        self::assertNotSame('0', $unavailable['emissionKgCo2e']);
    }

    public function testPurchasedOriginIsExplicitlyMappedPersistedAndRestored(): void
    {
        $catalog = new MaterialUiCatalog();
        $mapper = new MaterialEmissionRequestMapper($catalog);
        $calculator = $this->calculator();
        $post = $this->paintPost();

        $input = $mapper->map($this->request('POST', $post));
        self::assertSame('', $input->origin);
        self::assertSame('CALCULATED', $calculator->calculate($input)->status);

        $withoutSelection = $mapper->map($this->request('POST', [...$post, 'origin' => '']));
        self::assertNull($withoutSelection->origin);
        $pending = $calculator->calculate($withoutSelection);
        self::assertSame('PENDING_DATA', $pending->status);
        self::assertSame(['origin_required'], $pending->messages);

        $context = $this->context();
        $controller = $this->controller();
        $preview = $this->preview($controller, $context['active'], $post);
        self::assertSame('CALCULATED', $preview['status']);
        self::assertSame('34.45879792', $preview['emissionKgCo2e']);

        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('material_emission_v1_create'));
        $persisted = null;
        $response = $controller->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            $mapper,
            $this->recordService(1, $persisted),
            $catalog,
            $this->storage(),
            $this->attachmentManager(),
        );
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        $details = (string) $persisted->getCalculationDetails();
        self::assertStringNotContainsString(MaterialUiCatalog::ORIGIN_PURCHASED_PRESENTATION, $details);
        $stored = json_decode($details, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $stored['input']['origin']);
        self::assertTrue($stored['presentation']['purchasedOriginSelected']);

        $ignored = null;
        $edit = $controller->edit(
            $persisted,
            $this->request('GET'),
            $context['active'],
            $context['categories'],
            $context['projects'],
            $mapper,
            $this->recordService(0, $ignored),
            new MaterialEmissionSnapshot(),
            $catalog,
            $this->storage(),
            $this->attachmentManager(),
        );
        $duplicate = $controller->duplicate(
            $persisted,
            $this->request('GET'),
            $context['active'],
            $context['categories'],
            new MaterialEmissionSnapshot(),
            $catalog,
        );
        self::assertSame(MaterialUiCatalog::ORIGIN_PURCHASED_PRESENTATION, $this->initialFormValues((string) $edit->getContent())['origin']);
        self::assertSame(MaterialUiCatalog::ORIGIN_PURCHASED_PRESENTATION, $this->initialFormValues((string) $duplicate->getContent())['origin']);

        $editRequest = $this->request('POST', [...$post, 'notes' => 'Compra actualizada']);
        $editRequest->request->set('_token', $this->csrfToken('material_emission_v1_edit_'.$persisted->getId()));
        $updated = null;
        $editResponse = $controller->edit(
            $persisted,
            $editRequest,
            $context['active'],
            $context['categories'],
            $context['projects'],
            $mapper,
            $this->recordService(1, $updated),
            new MaterialEmissionSnapshot(),
            $catalog,
            $this->storage(),
            $this->attachmentManager(),
        );
        self::assertSame(Response::HTTP_FOUND, $editResponse->getStatusCode());
        self::assertSame($persisted, $updated);
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $persisted->getStatus());
        self::assertSame(34.45879792, $persisted->getEmission());
        self::assertSame('Compra actualizada', $persisted->getNotes());
        $updatedSnapshot = json_decode((string) $persisted->getCalculationDetails(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $updatedSnapshot['input']['origin']);
        self::assertTrue($updatedSnapshot['presentation']['purchasedOriginSelected']);
    }

    public function testHistoricalPurchasedOriginRequiresExplicitSelectionBeforeEdit(): void
    {
        $context = $this->context();
        $catalog = new MaterialUiCatalog();
        $mapper = new MaterialEmissionRequestMapper($catalog);
        $controller = $this->controller();
        $record = $this->recordFromPost($context, $this->paintPost(), 330);
        $originalDetails = $record->getCalculationDetails();
        $originalAmount = $record->getAmount();
        $originalEmission = $record->getEmission();
        $originalStatus = $record->getStatus();

        $stored = json_decode((string) $originalDetails, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $stored['input']['origin']);
        self::assertSame([], $stored['presentation']);

        $request = $this->request('POST', [...$this->paintPost(), 'origin' => '', 'notes' => 'No debe guardarse']);
        $request->request->set('_token', $this->csrfToken('material_emission_v1_edit_330'));
        $ignored = null;
        $response = $controller->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            $mapper,
            $this->recordService(0, $ignored),
            new MaterialEmissionSnapshot(),
            $catalog,
            $this->storage(),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('Selecciona Comprado o Reutilizado antes de guardar', (string) $response->getContent());
        self::assertSame($originalDetails, $record->getCalculationDetails());
        self::assertSame($originalAmount, $record->getAmount());
        self::assertSame($originalEmission, $record->getEmission());
        self::assertSame($originalStatus, $record->getStatus());
        self::assertNull($record->getNotes());

        $selectedRequest = $this->request('POST', [...$this->paintPost(), 'notes' => 'Origen confirmado']);
        $selectedRequest->request->set('_token', $this->csrfToken('material_emission_v1_edit_330'));
        $updated = null;
        $selectedResponse = $controller->edit(
            $record,
            $selectedRequest,
            $context['active'],
            $context['categories'],
            $context['projects'],
            $mapper,
            $this->recordService(1, $updated),
            new MaterialEmissionSnapshot(),
            $catalog,
            $this->storage(),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_FOUND, $selectedResponse->getStatusCode());
        self::assertSame($record, $updated);
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $record->getStatus());
        self::assertSame(34.45879792, $record->getEmission());
        self::assertSame('Origen confirmado', $record->getNotes());
        $confirmedSnapshot = json_decode((string) $record->getCalculationDetails(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $confirmedSnapshot['input']['origin']);
        self::assertTrue($confirmedSnapshot['presentation']['purchasedOriginSelected']);
    }

    public function testCreatePersistsModernRecordAndCompleteSnapshot(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->woodPost() + ['amount' => '999', 'emission' => '999']);
        $request->request->set('_token', $this->csrfToken('material_emission_v1_create'));
        $persisted = null;

        $response = $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new MaterialEmissionRequestMapper(new MaterialUiCatalog()),
            $this->recordService(1, $persisted),
            new MaterialUiCatalog(),
            $this->storage(),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        self::assertSame('Materiales', $persisted->getCategory()?->getName());
        self::assertSame(100.0, $persisted->getAmount());
        self::assertSame(26.950416, $persisted->getEmission());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $persisted->getStatus());

        $snapshot = json_decode((string) $persisted->getCalculationDetails(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('material-v1', $snapshot['version']);
        self::assertSame('wood', $snapshot['input']['family']);
        self::assertSame('Madera', $snapshot['input']['activity']);
        self::assertSame('100', $snapshot['calculation']['normalizedAmount']);
        self::assertSame(2026, $snapshot['calculation']['factorTraces'][0]['factorYear']);
        self::assertArrayHasKey('metadata', $snapshot['calculation']['factorTraces'][0]);
        self::assertStringNotContainsString('999', (string) $persisted->getCalculationDetails());
    }

    public function testEditRecalculatesAndDuplicateOnlyPrefillsFunctionalData(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $post = $this->woodPost();
        $post['inputQuantity'] = '20';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('material_emission_v1_edit_300'));
        $ignored = null;

        $response = $this->controller()->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new MaterialEmissionRequestMapper(new MaterialUiCatalog()),
            $this->recordService(1, $ignored),
            new MaterialEmissionSnapshot(),
            new MaterialUiCatalog(),
            $this->storage(),
            $this->attachmentManager(),
        );
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame(20.0, $record->getAmount());
        self::assertSame(5.3900832, $record->getEmission());
        $editedSnapshot = json_decode((string) $record->getCalculationDetails(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('FSC', $editedSnapshot['input']['sustainabilitySeal']);
        self::assertSame('Madera maciza de pino radiata o insignis', $editedSnapshot['input']['woodType']);

        $duplicate = $this->controller()->duplicate(
            $record,
            $this->request('GET', query: ['page' => '2']),
            $context['active'],
            $context['categories'],
            new MaterialEmissionSnapshot(),
            new MaterialUiCatalog(),
        );
        $content = (string) $duplicate->getContent();
        self::assertStringContainsString('action="/backend/emission/new-material-v1?', $content);
        self::assertStringContainsString('value="20"', $content);
        self::assertStringNotContainsString('material_emission_v1_edit_300', $content);
        self::assertSame(1, preg_match('/data-material-v1-form-initial-value="([^"]+)"/', $content, $matches));
        $initial = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('FSC', $initial['sustainabilitySeal']);
        self::assertSame('Madera maciza de pino radiata o insignis', $initial['woodType']);
    }

    public function testEditAndDuplicatePreserveMaterialSelections(): void
    {
        $context = $this->context();
        $cases = [
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_PAPER,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'weight',
                    'inputQuantity' => '10',
                    'inputUnit' => 'kg',
                    'paperFormat' => 'A4 (210 x 297)',
                ],
                ['paperFormat' => 'A4 (210 x 297)', 'measurementMethod' => 'weight'],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_PAPER,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'weight',
                    'inputQuantity' => '10',
                    'inputUnit' => 'kg',
                ],
                ['paperFormat' => null, 'measurementMethod' => 'weight'],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_PAPER,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'packages',
                    'inputQuantity' => '2',
                    'paperFormat' => 'A4 (210 x 297)',
                    'sheetsPerPackage' => '500',
                    'sustainabilitySeal' => 'PEFC',
                ],
                ['paperFormat' => 'A4 (210 x 297)', 'sustainabilitySeal' => 'PEFC'],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_CARDBOARD,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'weight',
                    'inputQuantity' => '10',
                    'inputUnit' => 'kg',
                    'cardboardStructure' => 'Cartón corrugado de 7 capas',
                    'sustainabilitySeal' => 'FSC',
                ],
                ['cardboardStructure' => 'Cartón corrugado de 7 capas', 'sustainabilitySeal' => 'FSC'],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_METAL,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'weight',
                    'inputQuantity' => '10',
                    'inputUnit' => 'kg',
                    'metalMaterial' => 'Cobre',
                    'metalForm' => 'Tubos',
                ],
                ['metalMaterial' => 'Cobre', 'metalForm' => 'Tubos'],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => 'Tereftalato de polietileno (PET)',
                    'origin' => 'Reutilizado',
                    'measurementMethod' => 'weight',
                    'inputQuantity' => '10',
                    'inputUnit' => 'kg',
                ],
                ['activity' => 'Tereftalato de polietileno (PET)', 'origin' => 'Reutilizado'],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_WOOD,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'dimensions',
                    'unitCount' => '1',
                    'boardFamily' => 'DM o MDF',
                    'boardThickness' => '10 mm',
                ],
                ['boardFamily' => 'DM o MDF', 'boardThickness' => '10 mm', 'woodType' => null],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_WOOD,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'dimensions',
                    'unitCount' => '1',
                    'woodType' => 'Desconocida / promedio',
                    'lengthMeters' => '1',
                    'widthMeters' => '1',
                    'thicknessMeters' => '1',
                ],
                ['woodType' => 'Desconocida / promedio', 'boardFamily' => null, 'boardThickness' => null],
            ],
            [
                [
                    ...$this->basePost(),
                    'activity' => MaterialUiCatalog::ACTIVITY_WOOD,
                    'origin' => 'Producción de materia prima',
                    'measurementMethod' => 'dimensions',
                    'unitCount' => '1',
                    'woodType' => 'Madera maciza de pino radiata o insignis',
                    'boardFamily' => 'DM o MDF',
                    'boardThickness' => '10 mm',
                ],
                [
                    'woodType' => 'Madera maciza de pino radiata o insignis',
                    'boardFamily' => 'DM o MDF',
                    'boardThickness' => '10 mm',
                ],
            ],
        ];

        foreach ($cases as $index => [$post, $expected]) {
            $record = $this->recordFromPost($context, $post, 310 + $index);
            $catalog = new MaterialUiCatalog();
            $ignored = null;
            $edit = $this->controller()->edit(
                $record,
                $this->request('GET'),
                $context['active'],
                $context['categories'],
                $context['projects'],
                new MaterialEmissionRequestMapper($catalog),
                $this->recordService(0, $ignored),
                new MaterialEmissionSnapshot(),
                $catalog,
                $this->storage(),
                $this->attachmentManager(),
            );
            $duplicate = $this->controller()->duplicate(
                $record,
                $this->request('GET'),
                $context['active'],
                $context['categories'],
                new MaterialEmissionSnapshot(),
                $catalog,
            );

            foreach ($expected as $field => $value) {
                self::assertSame($value, $this->initialFormValues((string) $edit->getContent())[$field]);
                self::assertSame($value, $this->initialFormValues((string) $duplicate->getContent())[$field]);
            }
        }
    }

    public function testEditAndDuplicateInferClothingGroupForLegacySnapshot(): void
    {
        $context = $this->context();
        $record = $this->legacyClothingRecord($context);
        $controller = $this->controller();
        $catalog = new MaterialUiCatalog();
        $ignored = null;

        $edit = $controller->edit(
            $record,
            $this->request('GET'),
            $context['active'],
            $context['categories'],
            $context['projects'],
            new MaterialEmissionRequestMapper($catalog),
            $this->recordService(0, $ignored),
            new MaterialEmissionSnapshot(),
            $catalog,
            $this->storage(),
            $this->attachmentManager(),
        );
        $editInitial = $this->initialFormValues((string) $edit->getContent());
        self::assertSame('Parte de arriba', $editInitial['clothingGroup']);
        self::assertSame('Camiseta manga corta', $editInitial['subproduct']);

        $duplicate = $controller->duplicate(
            $record,
            $this->request('GET'),
            $context['active'],
            $context['categories'],
            new MaterialEmissionSnapshot(),
            $catalog,
        );
        $duplicateInitial = $this->initialFormValues((string) $duplicate->getContent());
        self::assertSame('Parte de arriba', $duplicateInitial['clothingGroup']);
        self::assertSame('Camiseta manga corta', $duplicateInitial['subproduct']);
    }

    /** @return array<string, mixed> */
    private function preview(MaterialEmissionController $controller, ActiveProjectService $active, array $post): array
    {
        $request = $this->request('POST', $post);
        $request->request->set('_preview_token', $this->csrfToken('material_emission_v1_preview'));
        $response = $controller->preview($request, $active, new MaterialEmissionRequestMapper(new MaterialUiCatalog()), $this->calculator());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{project: Project, category: Category, phase: ProjectPhaseDate, active: ActiveProjectService&MockObject, categories: CategoryRepository&MockObject, projects: ProjectRepository&MockObject} */
    private function context(): array
    {
        $project = (new Project())->setName('Proyecto Materiales')->setType('rodaje')->setCountry('ES');
        $this->setId($project, 10);
        $category = (new Category())->setName('Materiales');
        $this->setId($category, 20);
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2022-01-01'))
            ->setEndDate(new \DateTimeImmutable('2027-12-31'));
        $project->addPhaseDate($phase);
        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($project);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('findOneBy')->willReturn($category);
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findPhaseByDate')->willReturn($phase);

        return compact('project', 'category', 'phase', 'active', 'categories', 'projects');
    }

    private function controller(): MaterialEmissionController
    {
        $controller = new MaterialEmissionController();
        $controller->setContainer(self::getContainer());
        $user = (new \App\Entity\User())
            ->setName('Admin')->setSurnames('User')->setEmail('admin@example.test')
            ->setPassword('password')->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);

        return $controller;
    }

    private function request(string $method, array $post = [], array $query = []): Request
    {
        $request = new Request($query, $post, [], [], [], ['REQUEST_METHOD' => $method]);
        $request->setLocale('es');
        $request->attributes->set('_route', 'backend_emission_new_material_v1');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function calculator(): MaterialEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new MaterialEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForApplicabilityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool =>
                        'material' === $categoryKey
                        && $factor->getFunctionalKey() === $functionalKey
                        && null !== $factor->getActivityYear()
                        && $factor->getActivityYear() <= $activityYear
                        && (null === $factor->getYear() || $factor->getYear() <= $activityYear)
                        && in_array($factor->getTemporalType(), [
                            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
                            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
                            EmissionFactor::TEMPORAL_TYPE_RULE,
                        ], true),
                );

                usort(
                    $candidates,
                    static fn (EmissionFactor $left, EmissionFactor $right): int =>
                        $right->getActivityYear() <=> $left->getActivityYear()
                        ?: strcmp((string) $left->getFactorId(), (string) $right->getFactorId()),
                );

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('material' === $categoryKey && $temporalType === $factor->getTemporalType() && $functionalKey === $factor->getFunctionalKey()) {
                        return $factor;
                    }
                }

                return null;
            },
        );
        $catalog = new MaterialUiCatalog();

        return new MaterialEmissionCalculator(
            $catalog,
            new MaterialAmountNormalizer($catalog),
            new MaterialFactorResolver(new EmissionFactorResolver($repository, $keyGenerator), $catalog),
        );
    }

    private function recordService(int $persistCalls, ?EmissionRecord &$persisted): MaterialEmissionRecordService
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::exactly($persistCalls))->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                if ($entity instanceof EmissionRecord) {
                    $persisted = $entity;
                    if (null === $entity->getId()) {
                        $this->setId($entity, 301);
                    }
                }
            },
        );
        $manager->expects(self::exactly($persistCalls))->method('flush');

        return new MaterialEmissionRecordService($this->calculator(), new MaterialEmissionSnapshot(), $manager);
    }

    private function attachmentManager(): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');

        return $manager;
    }

    private function storage(): EmissionRecordAttachmentStorage
    {
        return new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-material-test');
    }

    /** @param array<string, mixed> $context */
    private function record(array $context): EmissionRecord
    {
        return $this->recordFromPost($context, $this->woodPost(), 300);
    }

    /** @param array<string, mixed> $context
     *  @param array<string, string> $post
     */
    private function recordFromPost(array $context, array $post, int $id): EmissionRecord
    {
        $input = (new MaterialEmissionRequestMapper(new MaterialUiCatalog()))->map($this->request('POST', $post));
        $result = $this->calculator()->calculate($input);
        $record = (new EmissionRecord())
            ->setProject($context['project'])->setPhase($context['phase'])->setCategory($context['category'])
            ->setAmount(null === $result->normalizedAmount ? null : (float) $result->normalizedAmount)
            ->setEmission(null === $result->emissionKgCo2e ? null : (float) $result->emissionKgCo2e)
            ->setStatus($result->status)->setRegisteredAt(new \DateTimeImmutable('2026-01-01'))
            ->setCalculationDetails((new MaterialEmissionSnapshot())->encode($input, $result));
        $this->setId($record, $id);

        return $record;
    }

    /** @param array<string, mixed> $context */
    private function legacyClothingRecord(array $context): EmissionRecord
    {
        $post = array_diff_key($this->clothingPost(), ['clothingGroup' => true]);
        $input = (new MaterialEmissionRequestMapper(new MaterialUiCatalog()))->map($this->request('POST', $post));
        $result = $this->calculator()->calculate($input);
        $snapshot = json_decode(
            (new MaterialEmissionSnapshot())->encode($input, $result),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        unset($snapshot['input']['clothingGroup']);
        $record = (new EmissionRecord())
            ->setProject($context['project'])->setPhase($context['phase'])->setCategory($context['category'])
            ->setAmount((float) $result->normalizedAmount)->setEmission((float) $result->emissionKgCo2e)
            ->setStatus($result->status)->setRegisteredAt(new \DateTimeImmutable('2026-01-01'))
            ->setCalculationDetails(json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->setId($record, 302);

        return $record;
    }

    /** @return array<string, mixed> */
    private function initialFormValues(string $content): array
    {
        self::assertSame(1, preg_match('/data-material-v1-form-initial-value="([^"]+)"/', $content, $matches));

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> */
    private function formFieldPositions(string $content): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $positions = [];
        foreach ((new \DOMXPath($document))->query('//form//*[@name]') ?: [] as $position => $field) {
            $name = $field->getAttribute('name');
            if (!isset($positions[$name])) {
                $positions[$name] = $position;
            }
        }

        return $positions;
    }

    /** @return array<string, string> */
    private function basePost(): array
    {
        return ['startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'country' => 'ESP'];
    }

    /** @return array<string, string> */
    private function woodPost(): array
    {
        return [
            ...$this->basePost(),
            'activity' => 'Madera',
            'family' => 'wood',
            'origin' => 'Producción de materia prima',
            'measurementMethod' => 'weight',
            'inputQuantity' => '100',
            'inputUnit' => 'kg',
            'woodType' => 'Madera maciza de pino radiata o insignis',
            'sustainabilitySeal' => 'FSC',
            'notes' => 'Madera comprada',
        ];
    }

    /** @return array<string, string> */
    private function clothingPost(): array
    {
        return [
            ...$this->basePost(),
            'activity' => MaterialUiCatalog::ACTIVITY_CLOTHING,
            'family' => 'clothing',
            'subproduct' => 'Camiseta manga corta',
            'origin' => 'Materia prima virgen',
            'measurementMethod' => 'units',
            'unitCount' => '2',
            'clothingGroup' => 'Parte de arriba',
        ];
    }

    /** @return array<string, string> */
    private function paintPost(): array
    {
        return [
            ...$this->basePost(),
            'family' => 'paint',
            'activity' => MaterialUiCatalog::ACTIVITY_PAINT,
            'subproduct' => 'Pintura con base al agua',
            'origin' => MaterialUiCatalog::ORIGIN_PURCHASED_PRESENTATION,
            'measurementMethod' => 'volume',
            'inputQuantity' => '10',
            'inputUnit' => 'l',
        ];
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionClass($entity))->getProperty('id')->setValue($entity, $id);
    }
}
