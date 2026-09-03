<?php

namespace App\DataFixtures;

use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Entity\Department;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class CrewCatalogFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * Correspondencias explícitas aprobadas entre el catálogo de equipo y la
     * taxonomía departamental de medidas. Los casos ambiguos quedan ausentes.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const MEASURE_DEPARTMENT_MAPPINGS = [
        CrewDepartment::SCOPE_FILMING => [
            'ARTE' => ['Arte'],
            'ATREZZO' => ['Atrezzo'],
            'CASTING' => ['Casting'],
            'CATERING' => ['Catering'],
            'CONSTRUCCIÓN' => ['Construcción'],
            'CONTABILIDAD' => ['Contabilidad'],
            'DIRECCIÓN' => ['Dirección'],
            'ELÉCTRICO' => ['Eléctrico'],
            'FOTOGRAFÍA Y CÁMARA' => ['Fotografía y Cámara'],
            'GUION' => ['Guion y Dirección'],
            'HOME ECONOMIST' => ['Home Economist'],
            'LOCALIZACIONES' => ['Localizaciones'],
            'MAQUILLAJE Y PELUQUERÍA' => ['Maquillaje y Peluquería'],
            'MAQUINISTA Y GRIP' => ['Maquinista y Grip'],
            'POSTPRODUCCIÓN' => ['Postproducción'],
            'PRODUCCIÓN' => ['Producción'],
            'SFX' => ['SFX'],
            'SONIDO' => ['Sonido'],
            'SOSTENIBILIDAD' => ['Sostenibilidad'],
            'TRANSPORTE' => ['Transporte'],
            'VESTUARIO' => ['Vestuario'],
            'VETERINARIO Y ANIMALES' => ['Veterinario y Animales'],
        ],
        CrewDepartment::SCOPE_EVENT => [
            'PRODUCCIÓN' => ['Producción'],
            'DIRECCIÓN TÉCNICA' => ['Técnica (Sonido, Luz, AV)'],
            'ILUMINACIÓN' => ['Técnica (Sonido, Luz, AV)'],
            'SONIDO' => ['Técnica (Sonido, Luz, AV)'],
            'VÍDEO' => ['Técnica (Sonido, Luz, AV)'],
            'RETRANSMISIÓN Y STREAMING' => ['Técnica (Sonido, Luz, AV)'],
            'RIGGING Y ESTRUCTURAS' => ['Montaje y Escenografía'],
            'ESCENOGRAFÍA' => ['Montaje y Escenografía'],
            'ARTISTAS Y CONTENIDO ESCÉNICO' => ['Programación y Contenidos'],
            'CATERING' => ['Catering'],
            'ATENCIÓN AL PÚBLICO' => ['Atención al Público'],
            'SEGURIDAD Y PREVENCIÓN' => ['Seguridad'],
            'LOGÍSTICA Y TRANSPORTE' => ['Logística y Transporte'],
            'RECINTO Y PERMISOS' => ['Espacio / Recinto'],
            'COMUNICACIÓN Y MARKETING' => ['Comunicación y Marketing'],
            'ADMINISTRACIÓN' => ['Administración y Finanzas'],
            'SOSTENIBILIDAD' => ['Sostenibilidad'],
            'ACCESIBILIDAD' => ['Accesibilidad e Inclusión'],
        ],
        CrewDepartment::SCOPE_ANIMATION => [
            'DESARROLLO Y GUION' => ['Guion y Dirección'],
            'PRODUCCIÓN' => ['Producción'],
            'GESTIÓN DE PRODUCCIÓN TÉCNICA' => ['Producción'],
            'DIRECCIÓN' => ['Dirección'],
            'ARTE Y DISEÑO VISUAL' => ['Arte'],
            'LAYOUT Y CÁMARA' => ['Fotografía y Cámara'],
            'COLOR Y ACABADO' => ['Postproducción'],
            'SONIDO Y MÚSICA' => ['Sonido'],
            'POSTPRODUCCIÓN Y ENTREGAS' => ['Postproducción'],
        ],
    ];

    /**
     * Catálogos oficiales de Equipo Técnico facilitados para la tarea #37.
     *
     * @var array<string, list<array{department: string, positions: list<string>}>>
     */
    private const CATALOGS = [
        CrewDepartment::SCOPE_FILMING => [
            [
                'department' => 'ARTE',
                'positions' => [
                    'Diseñador/a de producción',
                    'Director/a de arte',
                    'Ayudante de dirección de arte',
                    'Ayudante de arte',
                    'Auxiliar de decoración',
                    'Regidor/a de arte',
                    'Dibujante',
                    'Grafista',
                    'Rotulista',
                    'Concept art',
                ],
            ],
            [
                'department' => 'ATREZZO',
                'positions' => [
                    'Ambientador/a',
                    'Ayudante de ambientación',
                    'Atrecista',
                    'Prop buyer',
                    'Prop maker / constructor de atrezzo',
                    'Utilero/a de set',
                ],
            ],
            [
                'department' => 'CASTING',
                'positions' => [
                    'Director/a de casting',
                    'Ayudante de casting',
                    'Auxiliar de casting',
                    'Coordinador/a de figuración',
                    'Representante de actores',
                ],
            ],
            [
                'department' => 'CATERING',
                'positions' => [
                    'Jefe/a de catering',
                    'Cocinero/a',
                    'Ayudante de cocina',
                    'Camarero/a',
                    'Auxiliar de catering',
                    'Repartidor/a',
                    'Nutricionista',
                ],
            ],
            [
                'department' => 'CONSTRUCCIÓN',
                'positions' => [
                    'Jefe/a de construcción',
                    'Constructor/a de decorados',
                    'Carpintero/a',
                    'Escultor/a',
                    'Maquetista',
                    'Pintor/a',
                    'Cerrajero/a',
                    'Estuquista',
                ],
            ],
            [
                'department' => 'CONTABILIDAD',
                'positions' => [
                    'Controller',
                    'Contable de producción',
                    'Auxiliar contable',
                    'Pagador/a (cashier)',
                    'Asesoría financiera',
                ],
            ],
            [
                'department' => 'DIRECCIÓN',
                'positions' => [
                    'Director/a',
                    'Codirector/a',
                    'Realizador/a',
                    'Ayudante de dirección',
                    '2º Ayudante de dirección',
                    '3er. Ayudante de dirección',
                    'Auxiliar de dirección',
                    'Script',
                    'Coach actores',
                    'Coreógrafo/a',
                    'Coordinador/a de intimidad',
                    'Tutor/a de menores',
                ],
            ],
            [
                'department' => 'ELÉCTRICO',
                'positions' => [
                    'Jefe/a de eléctricos',
                    'Best boy',
                    'Iluminador/a espectáculos',
                    'Eléctrico/a',
                    'Grupista',
                    'Rigger eléctrico',
                    'Técnico/a de mesa',
                ],
            ],
            [
                'department' => 'FOTOGRAFÍA Y CÁMARA',
                'positions' => [
                    'Director/a de fotografía',
                    'Cámara',
                    '2º/2ª Operador/a',
                    'Ayudante de cámara',
                    'Foquista',
                    'Auxiliar de cámara',
                    'Claquetista',
                    'DIT',
                    'Data wrangler / Digital loader',
                    'Video assist',
                    'Operador/a steadycam',
                    'Ayudante steadycam',
                    'Operador/a dron',
                    'Ayudante dron',
                    'Operador/a cámara submarina',
                    'Foto fija',
                    'Fotógrafo/a',
                    'Ayudante otógrafo/a',
                    'Making off',
                ],
            ],
            [
                'department' => 'GUION',
                'positions' => [
                    'Guionista',
                    'Coordinador/a de guion',
                    'Editor/a de guion',
                    'Dialoguista',
                    'Adaptador/a',
                    'Documentalista de guion',
                ],
            ],
            [
                'department' => 'HOME ECONOMIST',
                'positions' => [
                    'Home economist / Food stylist',
                    'Ayudante Home economist / Food stylist',
                ],
            ],
            [
                'department' => 'LOCALIZACIONES',
                'positions' => [
                    'Jefe/a de localizaciones',
                    'Localizador/a',
                    'Ayudante de localizaciones',
                    'Asistente de localizaciones',
                    'Guarda de localización',
                ],
            ],
            [
                'department' => 'MAQUILLAJE Y PELUQUERÍA',
                'positions' => [
                    'Jefe/a de maquillaje',
                    'Jefe/a de peluquería',
                    'Maquillador/a',
                    'Peluquero/a',
                    'Ayte. de maquillaje/peluquería',
                    'Caracterizador/a (FX)',
                    'Postizos y pelucas',
                ],
            ],
            [
                'department' => 'MAQUINISTA Y GRIP',
                'positions' => [
                    'Jefe/a de maquinistas',
                    'Maquinista',
                    'Dolly grip',
                    'Operador/a de grúa / cabeza caliente',
                    'Rigger',
                ],
            ],
            [
                'department' => 'POSTPRODUCCIÓN',
                'positions' => [
                    'Supervisor/a de postproducción',
                    'Coordinador/a de postproducción',
                    'Data manager',
                    'Montador/a',
                    'Ayudante de montaje',
                    'Auxiliar de montaje',
                    'Supervisor/a VFX',
                    'Técnico/a VFX',
                    'Composición digital',
                    'Conformado digital',
                    'Etalonaje',
                    'Masterización',
                    'Diseñador/a gráfico/a',
                    'Animador/a',
                    'Storyboard artist',
                    'Modelador/a 3D',
                    'Motion graphics',
                    'Rotoscopista',
                    'Director/a de doblaje',
                    'Mezclador/a',
                    'Ayudante de mezclas',
                    'Montador/a de sonido',
                    'Efectos sala / Foley artist',
                    'Diseñador/a de sonido',
                    'Editor/a de diálogos',
                    'Subtitulador/a',
                ],
            ],
            [
                'department' => 'PRODUCCIÓN',
                'positions' => [
                    'Productor/a ejecutivo/a',
                    'Productor/a',
                    'Productor/a delegado/a',
                    'Director/a de producción',
                    'Jefe/a de producción',
                    'Coordinador/a de producción',
                    'Secretario/a de producción',
                    'Ayudante de producción',
                    'Auxiliar de producción',
                    'Regidor/a',
                    'Runner',
                    'Responsable Contratación',
                    'Auxiliar de contratación',
                    'Fixer',
                    'Abogado/a audiovisual',
                    'Prevención de Riesgos',
                    'Médico/a - enfermero/a de rodaje',
                    'Seguridad',
                    'Documentalista',
                    'Agente de ventas internacionales',
                    'Jefe/a de prensa',
                    'Marketing y Relaciones Públicas',
                    'Community manager',
                    'Periodista',
                    'Redactor/a',
                    'Crítico/a de cine',
                    'Presentador/a',
                    'Traductor/a',
                    'Intérprete',
                ],
            ],
            [
                'department' => 'SFX',
                'positions' => [
                    'Jefe/a FX mecánicos',
                    'Jefe/a de especialistas',
                    'Maestro/a de armas',
                    'Armero/a',
                    'Especialista',
                    'Doble de acción',
                    'Conductor/a especialista',
                    'Ayudante FX',
                    'Técnico/a de efectos especiales',
                    'Pirotécnico/a',
                    'Especialista acuático/a / buzo de seguridad',
                ],
            ],
            [
                'department' => 'SONIDO',
                'positions' => [
                    'Jefe/a de sonido directo',
                    'Ayte. sonido / microfonista',
                    'Ayudante de sonido',
                    'Auxiliar sonido',
                    'Técnico/a de grabación',
                    'Compositor/a',
                    'Director/a de orquesta',
                    'Director/a de coro',
                    'Arreglista',
                    'Supervisor/a musical',
                    'Ambientador/a musical',
                    'Producción de sonido/música',
                    'Técnico/a de playback',
                ],
            ],
            [
                'department' => 'SOSTENIBILIDAD',
                'positions' => [
                    'Eco consultor/a',
                    'Eco manager',
                    'Eco PA',
                ],
            ],
            [
                'department' => 'TRANSPORTE',
                'positions' => [
                    'Coordinador/a de transporte',
                    'Conductor/a',
                    'Chófer',
                    'Conductor/a de camión',
                    'Picture car / vehículos de escena',
                ],
            ],
            [
                'department' => 'VESTUARIO',
                'positions' => [
                    'Diseñador/a de vestuario',
                    'Ayudante de vestuario',
                    'Auxiliar de vestuario',
                    'Vestuarista de set',
                    'Modista',
                    'Sastre/a',
                    'Lavandería',
                ],
            ],
            [
                'department' => 'VETERINARIO Y ANIMALES',
                'positions' => [
                    'Adiestrador/a de animales',
                    'Adiestrador/a canino',
                    'Adiestrador/a cetrería',
                    'Caballista',
                ],
            ],
        ],
        CrewDepartment::SCOPE_EVENT => [
            [
                'department' => 'PRODUCCIÓN',
                'positions' => [
                    'Productor/a ejecutivo/a',
                    'Productor/a',
                    'Director/a de producción',
                    'Jefe/a de producción',
                    'Coordinador/a de producción',
                    'Ayudante de producción',
                    'Auxiliar de producción',
                    'Runner',
                ],
            ],
            [
                'department' => 'DIRECCIÓN Y SHOW',
                'positions' => [
                    'Director/a de escena',
                    'Realizador/a',
                    'Show caller',
                    'Regidor/a',
                    'Ayudante de regiduría',
                    'Guionista',
                    'Coordinador/a de artistas',
                ],
            ],
            [
                'department' => 'DIRECCIÓN TÉCNICA',
                'positions' => [
                    'Director/a técnico/a',
                    'Jefe/a técnico/a',
                    'Coordinador/a técnico/a',
                    'Delineante / CAD',
                ],
            ],
            [
                'department' => 'ILUMINACIÓN',
                'positions' => [
                    'Diseñador/a de iluminación',
                    'Jefe/a de eléctricos',
                    'Programador/a',
                    'Técnico/a de mesa',
                    'Eléctrico/a',
                    'Grupista',
                    'Operador/a de cañón seguidor',
                ],
            ],
            [
                'department' => 'SONIDO',
                'positions' => [
                    'Diseñador/a de sonido',
                    'Técnico/a de PA (FOH)',
                    'Técnico/a de monitores',
                    'Microfonista',
                    'Técnico/a de RF e intercom',
                    'Backliner',
                    'Auxiliar de sonido',
                ],
            ],
            [
                'department' => 'VÍDEO',
                'positions' => [
                    'Realizador/a',
                    'Mezclador/a de vídeo',
                    'Operador/a de cámara',
                    'Técnico/a de LED',
                    'Técnico/a de media server / playback',
                    'Proyeccionista',
                    'VJ / contenidos',
                ],
            ],
            [
                'department' => 'RETRANSMISIÓN Y STREAMING',
                'positions' => [
                    'Director/a de retransmisión',
                    'Productor/a de streaming',
                    'Técnico/a de unidad móvil (OB)',
                    'Mezclador/a de directo (switcher)',
                    'Operador/a de cámara ENG',
                    'Técnico/a de repetición (replay)',
                    'Técnico/a de grafismo y rotulación',
                    'Técnico/a de conectividad y señal (fibra, satélite, bonding)',
                    'Comentarista - narrador/a',
                    'Coordinador/a de zona mixta y prensa',
                    'Moderador/a de plataforma (evento híbrido)',
                ],
            ],
            [
                'department' => 'RIGGING Y ESTRUCTURAS',
                'positions' => [
                    'Jefe/a de rigging',
                    'Rigger',
                    'Montador/a de estructuras',
                    'Técnico/a de motores',
                    'Operador/a de plataforma / carretilla',
                ],
            ],
            [
                'department' => 'ESCENOGRAFÍA',
                'positions' => [
                    'Diseñador/a escenográfico/a',
                    'Jefe/a de montaje',
                    'Carpintero/a',
                    'Pintor/a',
                    'Atrecista',
                    'Ambientación floral',
                ],
            ],
            [
                'department' => 'VESTUARIO E IMAGEN',
                'positions' => [
                    'Diseñador/a de vestuario',
                    'Sastre/a',
                    'Maquillador/a',
                    'Peluquero/a',
                    'Estilista',
                ],
            ],
            [
                'department' => 'ARTISTAS Y CONTENIDO ESCÉNICO',
                'positions' => [
                    'Coreógrafo/a',
                    'Bailarín/a',
                    'Músico/a',
                    'Presentador/a',
                    'Maestro/a de ceremonias',
                    'DJ',
                    'Artista de sala',
                ],
            ],
            [
                'department' => 'FX',
                'positions' => [
                    'Técnico/a de pirotecnia',
                    'Técnico/a de efectos especiales (CO2, humo, confeti)',
                    'Técnico/a de láser',
                ],
            ],
            [
                'department' => 'CATERING',
                'positions' => [
                    'Jefe/a de catering',
                    'Cocinero/a',
                    'Ayudante de cocina',
                    'Camarero/a',
                    'Responsable de hospitality',
                    'Auxiliar de catering',
                ],
            ],
            [
                'department' => 'ATENCIÓN AL PÚBLICO',
                'positions' => [
                    'Jefe/a de sala',
                    'Responsable de acreditaciones',
                    'Ticketing',
                    'Acomodador/a',
                    'Azafato/a',
                    'Guardarropa',
                ],
            ],
            [
                'department' => 'SEGURIDAD Y PREVENCIÓN',
                'positions' => [
                    'Coordinador/a de seguridad',
                    'Jefe/a de equipo de seguridad',
                    'Controlador/a de accesos',
                    'Prevención de riesgos',
                    'Coordinador/a de evacuación',
                    'Médico/a - enfermero/a',
                    'Bombero/a de guardia',
                ],
            ],
            [
                'department' => 'SERVICIOS Y CONTROL DE AFOROS',
                'positions' => [
                    'Responsable de servicios generales',
                    'Coordinador/a de limpieza',
                    'Personal de limpieza',
                    'Responsable de aseos y sanitarios portátiles',
                    'Controlador/a de aforo',
                    'Coordinador/a de flujos y colas',
                    'Responsable de señalética',
                    'Personal de vallado y balizamiento',
                    'Coordinador/a de voluntarios',
                    'Responsable de suministros y consumibles',
                ],
            ],
            [
                'department' => 'LOGÍSTICA Y TRANSPORTE',
                'positions' => [
                    'Coordinador/a de logística',
                    'Conductor/a',
                    'Conductor/a de camión',
                    'Carretillero/a',
                    'Responsable de almacén',
                ],
            ],
            [
                'department' => 'RECINTO Y PERMISOS',
                'positions' => [
                    'Venue manager',
                    'Jefe/a de recinto',
                    'Responsable de permisos',
                    'Localizador/a de espacios',
                ],
            ],
            [
                'department' => 'COMUNICACIÓN Y MARKETING',
                'positions' => [
                    'Jefe/a de prensa',
                    'Marketing y Relaciones Públicas',
                    'Community manager',
                    'Fotógrafo/a',
                    'Making of',
                    'Diseñador/a gráfico/a',
                ],
            ],
            [
                'department' => 'ADMINISTRACIÓN',
                'positions' => [
                    'Controller',
                    'Contable',
                    'Pagador/a (cashier)',
                    'Asesoría financiera',
                    'Abogado/a',
                ],
            ],
            [
                'department' => 'SOSTENIBILIDAD',
                'positions' => [
                    'Eco manager',
                    'Eco consultor/a',
                    'Responsable de residuos',
                ],
            ],
            [
                'department' => 'ACCESIBILIDAD',
                'positions' => [
                    'Intérprete de lengua de signos',
                    'Técnico/a de audiodescripción',
                    'Traductor/a - intérprete',
                ],
            ],
        ],
        CrewDepartment::SCOPE_ANIMATION => [
            [
                'department' => 'DESARROLLO Y GUION',
                'positions' => [
                    'Guionista',
                    'Coordinador/a de guion',
                    'Editor/a de guion',
                    'Dialoguista',
                    'Consultor/a de historia',
                    'Documentalista',
                ],
            ],
            [
                'department' => 'PRODUCCIÓN',
                'positions' => [
                    'Productor/a ejecutivo/a',
                    'Productor/a',
                    'Productor/a de animación',
                    'Director/a de producción',
                    'Jefe/a de producción',
                    'Line producer',
                    'Coordinador/a de producción',
                    'Ayudante de producción',
                    'Auxiliar de producción',
                    'Runner',
                    'Responsable de contratación',
                ],
            ],
            [
                'department' => 'GESTIÓN DE PRODUCCIÓN TÉCNICA',
                'positions' => [
                    'Production manager',
                    'Coordinador/a de departamento',
                    'Responsable de planificación',
                    'Tracking / asset wrangler',
                ],
            ],
            [
                'department' => 'DIRECCIÓN',
                'positions' => [
                    'Director/a',
                    'Codirector/a',
                    'Director/a de animación',
                    'Director/a de secuencia',
                    'Ayudante de dirección',
                    'Script / continuidad',
                ],
            ],
            [
                'department' => 'ARTE Y DISEÑO VISUAL',
                'positions' => [
                    'Director/a de arte',
                    'Diseñador/a de producción',
                    'Concept artist',
                    'Diseñador/a de personajes',
                    'Diseñador/a de props',
                    'Diseñador/a de entornos',
                    'Color script / color key',
                    'Ilustrador/a',
                    'Matte painter',
                ],
            ],
            [
                'department' => 'STORYBOARD Y EDITORIAL',
                'positions' => [
                    'Supervisor/a de storyboard',
                    'Storyboard artist',
                    'Revisionista de board',
                    'Editor/a de animática',
                    'Montador/a',
                    'Ayudante de montaje',
                    'Timing / bar sheets',
                ],
            ],
            [
                'department' => 'LAYOUT Y CÁMARA',
                'positions' => [
                    'Supervisor/a de layout',
                    'Artista de layout',
                    'Director/a de fotografía',
                    'Operador/a de cámara virtual',
                    'Previz artist',
                ],
            ],
            [
                'department' => 'ANIMACIÓN 2D TRADICIONAL',
                'positions' => [
                    'Supervisor/a de animación',
                    'Animador/a clave',
                    'Asistente de animación',
                    'Intercalador/a',
                    'Clean-up artist',
                    'Trazado y color (ink & paint)',
                    'Animador/a de efectos 2D',
                    'Verificador/a (checker)',
                ],
            ],
            [
                'department' => 'ANIMACIÓN 2D DIGITAL Y CUT-OUT',
                'positions' => [
                    'Rigger 2D',
                    'Animador/a cut-out',
                    'Diseñador/a de rigs',
                    'Compositor/a 2D',
                ],
            ],
            [
                'department' => 'MODELADO Y ASSETS',
                'positions' => [
                    'Supervisor/a de modelado',
                    'Modelador/a de personajes',
                    'Modelador/a de entornos y props',
                    'Escultor/a digital',
                    'Artista de texturas',
                    'Look development artist',
                    'Groom artist',
                    'Shading artist',
                ],
            ],
            [
                'department' => 'RIGGING Y TÉCNICA DE PERSONAJE',
                'positions' => [
                    'Supervisor/a de rigging',
                    'Rigger de personajes',
                    'Technical animator',
                    'Character FX (ropa, pelo, simulación)',
                    'Creature TD',
                ],
            ],
            [
                'department' => 'ANIMACIÓN 3D',
                'positions' => [
                    'Supervisor/a de animación',
                    'Animador/a de personajes',
                    'Animador/a de criaturas',
                    'Animador/a de cámara',
                    'Blocking / polish artist',
                    'Crowd artist',
                ],
            ],
            [
                'department' => 'MOTION CAPTURE',
                'positions' => [
                    'Supervisor/a de captura',
                    'Técnico/a de mocap',
                    'Actor/actriz de captura',
                    'Solver / tracker de datos',
                    'Facial capture artist',
                ],
            ],
            [
                'department' => 'STOP MOTION - MARIONETAS Y CONSTRUCCIÓN',
                'positions' => [
                    'Constructor/a de marionetas',
                    'Fabricante de armaduras',
                    'Escultor/a',
                    'Moldes y siliconas',
                    'Fabricante de recambios faciales',
                    'Vestuario de marionetas',
                    'Pintor/a de acabados',
                ],
            ],
            [
                'department' => 'STOP MOTION - DECORADOS Y MINIATURAS',
                'positions' => [
                    'Jefe/a de decorados',
                    'Carpintero/a de maquetas',
                    'Maquetista',
                    'Pintor/a de maquetas',
                    'Atrecista de miniaturas',
                    'Rigger de set',
                ],
            ],
            [
                'department' => 'STOP MOTION - RODAJE',
                'positions' => [
                    'Director/a de animación stop motion',
                    'Animador/a stop motion',
                    'Ayudante de animación',
                    'Director/a de fotografía',
                    'Operador/a de cámara',
                    'Foquista',
                    'Técnico/a de motion control',
                    'Jefe/a de eléctricos',
                    'Eléctrico/a',
                    'DIT / frame grabber',
                    'Supervisor/a de continuidad',
                ],
            ],
            [
                'department' => 'OTRAS TÉCNICAS',
                'positions' => [
                    'Animador/a de plastilina (claymation)',
                    'Animador/a de recortables',
                    'Animador/a de arena y pintura sobre cristal',
                    'Pixilación',
                    'Rotoscopista',
                    'Animación directa sobre película',
                ],
            ],
            [
                'department' => 'VFX Y EFECTOS',
                'positions' => [
                    'Supervisor/a de VFX',
                    'Supervisor/a de FX',
                    'Artista de FX y simulaciones',
                    'Artista de partículas y dinámicas',
                    'Matchmove / tracking artist',
                    'Rotoscopista',
                    'Paint & prep artist',
                ],
            ],
            [
                'department' => 'ILUMINACIÓN, RENDER Y COMPOSICIÓN',
                'positions' => [
                    'Supervisor/a de iluminación',
                    'Iluminador/a (lighting artist)',
                    'Artista de render',
                    'Render wrangler',
                    'Supervisor/a de composición',
                    'Compositor/a',
                    'Stereo artist',
                ],
            ],
            [
                'department' => 'COLOR Y ACABADO',
                'positions' => [
                    'Etalonador/a',
                    'Supervisor/a de imagen',
                    'Conformado y masterización',
                    'Control de calidad (QC)',
                ],
            ],
            [
                'department' => 'PIPELINE Y TECNOLOGÍA',
                'positions' => [
                    'Supervisor/a técnico/a (CG supervisor)',
                    'Pipeline TD',
                    'Desarrollador/a de herramientas',
                    'Administrador/a de sistemas',
                    'Responsable de render farm',
                    'Responsable de datos y backups',
                ],
            ],
            [
                'department' => 'SONIDO Y MÚSICA',
                'positions' => [
                    'Diseñador/a de sonido',
                    'Montador/a de sonido',
                    'Efectos sala / Foley artist',
                    'Mezclador/a',
                    'Director/a de doblaje',
                    'Director/a de casting de voces',
                    'Actor/actriz de voz',
                    'Compositor/a',
                    'Supervisor/a musical',
                    'Editor/a musical',
                    'Técnico/a de grabación',
                ],
            ],
            [
                'department' => 'POSTPRODUCCIÓN Y ENTREGAS',
                'positions' => [
                    'Supervisor/a de postproducción',
                    'Coordinador/a de postproducción',
                    'Editor/a de tráilers',
                    'Subtitulador/a',
                    'Versiones y localización',
                    'Data manager / entregas',
                ],
            ],
            [
                'department' => 'ADMINISTRACIÓN Y LEGAL',
                'positions' => [
                    'Controller',
                    'Contable',
                    'Pagador/a (cashier)',
                    'Asesoría financiera',
                    'Abogado/a audiovisual',
                    'Responsable de coproducción e incentivos',
                ],
            ],
            [
                'department' => 'COMUNICACIÓN, RRHH Y SOSTENIBILIDAD',
                'positions' => [
                    'Jefe/a de prensa',
                    'Marketing y Relaciones Públicas',
                    'Community manager',
                    'Diseñador/a gráfico/a',
                    'Artista de key art',
                    'Responsable de RRHH',
                    'Prevención de riesgos',
                    'Eco manager',
                ],
            ],
        ],
    ];

    public static function getGroups(): array
    {
        return ['auxiliary', 'crew_catalog'];
    }

    public function getDependencies(): array
    {
        return [MeasureDepartmentFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $departmentRepository = $manager->getRepository(Department::class);

        foreach (self::CATALOGS as $scope => $departments) {
            foreach ($departments as $departmentIndex => $definition) {
                $department = (new CrewDepartment())
                    ->setName($definition['department'])
                    ->setScope($scope)
                    ->setSortOrder(($departmentIndex + 1) * 10);

                foreach ($definition['positions'] as $positionIndex => $positionName) {
                    $department->addPosition(
                        (new CrewPosition())
                            ->setName($positionName)
                            ->setSortOrder(($positionIndex + 1) * 10)
                    );
                }

                $measureProjectType = CrewDepartment::SCOPE_EVENT === $scope
                    ? CrewDepartment::SCOPE_EVENT
                    : CrewDepartment::SCOPE_FILMING;

                foreach (self::MEASURE_DEPARTMENT_MAPPINGS[$scope][$definition['department']] ?? [] as $departmentName) {
                    $measureDepartment = $departmentRepository->findOneBy([
                        'projectType' => $measureProjectType,
                        'name' => $departmentName,
                    ]);

                    if (!$measureDepartment instanceof Department) {
                        throw new \RuntimeException(sprintf(
                            'No existe Department "%s" para projectType "%s".',
                            $departmentName,
                            $measureProjectType
                        ));
                    }

                    $department->addCompatibleMeasureDepartment($measureDepartment);
                }

                $manager->persist($department);
            }
        }

        $manager->flush();
    }
}
