# Matriz interna de contenido y QA — Iteración 3

Este archivo no forma parte de los PDFs. “Código” significa verificación estática sobre controlador, formulario y plantilla actuales. “Navegador” solo se marca cuando existe evidencia visual facilitada o QA ejecutada.

## Administración

| Capítulo | Menú Super Admin | Menú Admin | Autorización backend observada | Acciones confirmadas | Evidencia principal | Estado / pendiente |
| --- | --- | --- | --- | --- | --- | --- |
| Usuarios | Sí | No | `ROLE_SUPER_ADMIN` en todos los métodos | listar, alta, editar datos/roles/verificación, impersonar desde UI, borrar con restricciones, asignar/desasignar proyectos | `AdminUserController`, `UserType`, templates; captura menú | Código + menú navegador. QA de formularios pendiente. |
| Medidas | Sí | No | Pantallas principales `ROLE_SUPER_ADMIN`; template y export PDF tienen huecos puntuales cubiertos por `/admin -> ROLE_ADMIN` | filtrar, alta, editar, borrar, importar, plantilla, exportar XLSX/PDF | `AdminMeasureController`, `MeasureType`, templates, docs de importación | Código. No probar importación destructiva. Revisar huecos de método fuera de #83. |
| Factores emisión | Sí | Sí | `ROLE_ADMIN` en todos los métodos | filtrar/paginar, alta, detalle, editar, borrar | `EmissionFactorController`, `EmissionFactorType`, templates, `emission-calculator.md` | Código. QA visual pendiente. |
| Tablas auxiliares | Sí | No | Sin deny específico; alcanzable por `ROLE_ADMIN` debido a `access_control /admin` | diez catálogos, listar, alta, editar, borrar con bloqueos | `AuxiliaryController`, `AuxiliaryType`, templates | Discrepancia menú/permisos. Manual operativo solo Super Admin. |
| Equipo técnico | Sí | No | Sin deny específico; alcanzable por `ROLE_ADMIN` | ámbitos, departamentos y puestos: alta, edición, borrado | `CrewCatalogController`, forms y templates | Discrepancia menú/permisos. No confundir con equipo de proyecto. |
| CMS | Sí | No | CMS base alcanzable por `ROLE_ADMIN`; Logos exige `ROLE_SUPER_ADMIN` | logos, ajustes de carrusel, tres contadores | `CmsController`, `LogoController`, templates | Permiso mixto y discrepancia de menú. QA de reflejo en portada pendiente. |
| IA | Sí | No | `ROLE_SUPER_ADMIN` | proveedor/modelos disponibles, seis bloques editoriales, guardar y restaurar defaults | `AiReportSettingController`, form, template, `ai-reporting.md` | Código. No llamadas reales a proveedor. |
| Diseño informes | Sí | No | `ROLE_SUPER_ADMIN` | seleccionar siete definiciones, generar/modificar borrador, preview/descarga, aprobar, descartar | `ReportDesignController`, `ReportDesignCatalog`, template | Código. Revisión visual Franc #65/#73 pendiente. |
| Planes comerciales | Sí | No | `ROLE_SUPER_ADMIN` | listar y editar por fase: precio, estado, orden, límites, scores y features | `CommercialPlanController`, form/templates, docs comerciales | Código. Cambios por proyecto pedidos por Franc no implementados aquí. |
| Comunicaciones | Sí | No | listado/descarga `ROLE_USER`, crear/editar/borrar `ROLE_ADMIN`, más `/admin -> ROLE_ADMIN` | subir, descripción, reemplazar, descargar, borrar | `CommunicationController`, `CommunicationFileType`, templates | Backend Admin confirmado; enlace Admin ausente. Destinatarios/notificaciones no existen. |

## Usuarios y roles

| Procedimiento | Evidencia | Estado |
| --- | --- | --- |
| Registro público con datos, condiciones y correo | `RegistrationController`, formulario y capturas facilitadas 01/11 | Código + navegador confirmado. |
| Confirmación y caducidad anunciada de un día | `EmailVerifier`/correo y captura Mailpit facilitada | Navegador confirmado; no se incluyen datos personales. |
| Rol inicial Editor (`ROLE_USER`) y cero proyectos | `User::getRoles`, registro y captura facilitada | Código + navegador confirmado. |
| Alta administrativa y hash inicial | `AdminUserController::new`, `UserType` | Código confirmado. No envía invitación/verificación. |
| Edición de rol/verificación | `AdminUserController::edit` | Código confirmado; QA navegador pendiente. |
| Contraseña en edición administrativa | `plainPassword` no mapeado y `edit()` no procesa hash | Incidencia confirmada. No instruir su uso; recomendar reset público. No corregir en #83. |
| Asignación/desasignación | controlador + template | Código confirmado: evita duplicados y bloquea retirar creador. |
| Eliminar cuenta creadora | controlador | Bloqueado expresamente. |
| `owner` / `member` | voters y `users-project-memberships-and-invitations.md` | No dan permisos diferentes actualmente. |
| Verificador #82 | código versionado y `qa-playwright/docs/begreen-82-verifier-qa.md` | Existe plan QA, pero no se encontró evidencia de aceptación final ejecutada. Mantener manual definitivo pendiente. |

## Capítulos comunes de proyecto

| Capítulo | Recorridos documentados | Evidencia comprobada | Estado / límite |
| --- | --- | --- | --- |
| Proyectos | alta en cinco pasos, edición, selección, equipo manual, plantilla, revisión y confirmación de importación | `ProjectController`, `ProjectType`, templates de formulario/equipo/revisión y traducciones | Código confirmado. No se ejecutó importación ni se modificó BD. |
| Plan | protocolo, recorrido de medidas, decisiones, medidas personalizadas, filtros, cierre, preview y exportaciones por tier | `PlanController`, templates `welcome/measures/done`, feature gate y status model | Código confirmado. No se generó narrativa ni se consumió proveedor. |
| BGoS | configuración de frecuencia, equipo, agenda día/semana/mes/total, jornadas y trayectos | `BgosController`, managers y templates `agenda/config/crew` | Código confirmado. No se guardaron jornadas reales. |
| Implementación | filtros, decisión, acción, evidencia/fuente, incidencia, estados y bloqueos de cierre | `PlanController::review`, servicios de estado, templates de detalle/evidencia y status model | Código confirmado. Verificación se mantiene separada; QA de rol navegador pendiente. |
| Calculadora | siete categorías; alta, edición, duplicación, borrado, preview y tres informes | controladores de categorías, `EmissionController`, `EmissionReportController` y templates | Código confirmado. No se ensayaron todas las combinaciones de campos/factores. |
| Informe Final | origen de datos, destacadas, edición, narrativa explícita, galería, portada, preview y descarga | `FinalReportController`, servicios de contenido/PDF y template `final_report/index` | Código confirmado. Revisión editorial Franc y QA navegador pendientes. |
| Cartelería / Compensación / Certificación | presencia en las ocho fases | tarjetas del Dashboard | Sin procedimiento completo; no se presentan como operativas. |

## Separación de manuales

- Administrador / Consultora incluye solo la entrada visible **Factores emisión** más recorridos de proyecto compartidos.
- Comunicaciones se retiró de la composición de Administrador porque no existe una entrada ordinaria en su menú. La autorización observada se conserva en esta matriz como discrepancia pendiente.
- Super Administrador conserva los diez accesos visibles y tiene introducción específica.
- Las introducciones ya no comparten texto ambiguo entre perfiles.

## Capturas reales pendientes

No existen PNG/JPEG de producto dentro de `docs/manuales/capturas/` al cerrar esta iteración. Las imágenes vistas en conversación no se consideran archivos reutilizables ni aportan trazabilidad suficiente. No se integran wireframes ni capturas reconstruidas.

| Captura necesaria | Rol | Paso exacto | Estado y motivo |
| --- | --- | --- | --- |
| Login, Registro y aviso de verificación | Público / Editor | Introducción: alta y confirmación | Pendiente de archivo DEV anonimizado. |
| Dashboard sin proyectos y selector activo | Editor / Admin | Introducción y Navegación | Pendiente; evitar nombres reales. |
| Usuarios: listado y ficha de asignación | Super Admin | Usuarios: editar perfil y asignar proyecto | Pendiente de sesión autenticada y anonimización de correo/teléfono. |
| Medidas: filtros y formulario | Super Admin | Medidas: localizar y crear | Pendiente de proyecto/catálogo seguro. |
| Tablas auxiliares y Equipo técnico global | Super Admin | Alta de elemento y puesto | Pendiente de entorno validado sin modificar datos reales. |
| CMS y Diseño informes | Super Admin | Comprobar portada / borrador-aprobado | Pendiente; no ejecutar proveedor ni publicar cambios. |
| Proyecto: resumen e importación de equipo | Admin | Crear proyecto / revisión previa | Pendiente de archivo de ejemplo autorizado. |
| BGoS: Configuración, Equipo y Agenda Día | Admin | Guardar frecuencia, perfil y jornada | Pendiente de datos de proyecto anonimizados. |
| Implementación: detalle y evidencia | Admin | Acción, fuente y estado resultante | Pendiente; ocultar documentos y nombres. |
| Calculadora: listado y preview | Admin | Nuevo registro y comprobación | Pendiente de actividad de prueba autorizada. |
| Informe Final: editor y vista previa | Admin | Guardar texto, galería y preview | Pendiente de imágenes y contenido autorizados. |

## QA pendiente tras Iteración 3

- Abrir ambos perfiles en DEV y comprobar opción de manual, nombre de descarga y ausencia en otros perfiles.
- Ejecutar los procedimientos con datos de prueba autorizados y capturar las pantallas de la tabla anterior.
- Resolver fuera de #83 la discrepancia de Comunicaciones, Tablas auxiliares, Equipo técnico y CMS entre navegación y autorización.
- Revisar visualmente diseños pendientes y obtener aceptación editorial del Informe Final.
- Confirmar formalmente el recorrido de Verificador antes de publicar un manual propio.
