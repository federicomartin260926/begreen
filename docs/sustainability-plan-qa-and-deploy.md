# Sustainability Plan QA and Deploy

Documento de cierre técnico de la fase **Plan de Sostenibilidad Be Green My Film**.

## Alcance cerrado

Esta fase deja operativos estos bloques:

- Catálogo de medidas editable desde admin.
- Planes comerciales por proyecto: `Basic`, `Standard`, `Pro`.
- Filtros multi-taxonomía y catálogo oficial por tier.
- PDF unificado del plan con watermark en `Basic`.
- Exportaciones MVP:
  - `Basic`: PDF unificado.
  - `Standard`: PDF agrupado por departamentos.
  - `Pro`: PDF/Excel por categorías, departamentos, áreas de impacto, triple balance y ODS.
- Funciones Pro colaborativas:
  - comentario visible por medida;
  - notas internas;
  - responsables por medida;
  - evidencias;
  - validación básica;
  - medidas personalizadas MVP.
- Gaming / niveles de compromiso por puntos:
  - Semilla
  - Planta
  - Árbol
  - Bosque
  - Selva
- Stripe Checkout MVP para upgrades de proyecto:
  - `Basic -> Standard`
  - `Basic -> Pro`
  - `Standard -> Pro`
  - facturas y enlaces generados por Stripe

## Orden de medidas en las vistas

- En la vista operativa de creación del plan, las medidas se ordenan por `categoría` o `departamento` según el `groupingBy` del protocolo, y después por nombre de medida.
- En la vista tabular/review del plan ya creado, las medidas se ordenan por `rank` de estado del plan y después por `nameReview` si existe, o por `name`.
- En las exportaciones agrupadas, el grupo se ordena por la taxonomía elegida y las filas se ordenan por nombre visible (`displayName`).

## Bloques de medidas

- El campo `Orden` del bloque es interno y no gobierna el recorrido del plan.
- El recorrido operativo siempre lo determinan las medidas y su ordenación en el flujo del plan.
- Los bloques solo subclasifican medidas dentro de un protocolo y pueden activar una pregunta previa opcional.

## Plan comercial y puntos

- La relación entre plan comercial y medidas oficiales es por puntuación, no por IDs fijos.
- Regla actual:
  - `Basic`: medidas de 4 y 5 puntos.
  - `Standard`: medidas de 3, 4 y 5 puntos.
  - `Pro`: medidas de 1, 2, 3, 4 y 5 puntos.
- Esta inclusión se calcula sobre el catálogo oficial del protocolo activo y se aplica después de excluir medidas no visibles del tier o saltadas por bloque.
- No existe una tabla ni una lista manual de “medidas permitidas por plan” en esta fase: la fuente de verdad es el catálogo importado y la lógica de negocio del plan.

## Checklist funcional por plan

### Basic

- Ve 50 medidas oficiales.
- El PDF unificado funciona.
- El PDF unificado lleva watermark.
- No accede a exportaciones agrupadas.
- No accede a funciones Pro.
- Tiene límite de 10 evidencias por proyecto.
- El nivel de compromiso se calcula sobre el catálogo permitido por tier.

### Standard

- Ve 100 medidas oficiales.
- El PDF unificado no lleva watermark.
- Tiene PDF agrupado por departamentos.
- No accede a exportaciones Pro.
- No accede a funciones Pro.
- El nivel de compromiso se calcula sobre el catálogo permitido por tier.

### Pro

- Ve 200 medidas oficiales.
- El PDF unificado no lleva watermark.
- Tiene PDF/Excel agrupados por categorías, departamentos, impacto, triple balance y ODS.
- Tiene comentarios visibles, notas internas, responsables y medidas personalizadas MVP.
- El nivel de compromiso se calcula sobre el catálogo oficial de medidas, sin mezclar las medidas custom en el total oficial.

## PDF unificado y QA visual

El PDF general comparte una única composición visual, pero respeta el tier efectivo de la fase comercial que se está descargando.

Reglas actuales:

- `Basic` mantiene la marca de agua y muestra literalmente las observaciones de las medidas.
- `Standard` y `Pro` no muestran literalmente las observaciones en el PDF general; las observaciones siguen disponibles en el plan y, cuando corresponde, forman parte del contexto enviado a la IA.
- Las medidas del PDF se muestran con el nombre canónico de `Measure` en forma de acción/imperativo, no como preguntas de revisión.
- La portada puede incorporar logos de las empresas participantes del proyecto. Los logos conservan proporción y se normalizan dentro de una caja común, sin recorte ni deformación.
- La portada mantiene resumen y compromiso en flujo conjunto para evitar solapamientos en proyectos con más metadatos, incluido Evento.
- Los encabezados de categoría usan bordes redondeados; check, texto y distintivo `Crítica` se mantienen alineados y el interlineado se ha compactado.
- El bloque futuro se presenta como `En el horizonte, para el próximo proyecto`.
- La paginación de categorías es explícita: los fragmentos grandes conservan página propia y se pueden agrupar como máximo dos fragmentos de categorías distintas cuando un presupuesto conservador de contenido indica que caben con seguridad.
- El PDF no depende de la preview del navegador para su maquetación.

### Preview HTML

La ruta:

`GET /backend/plan/closure/preview`

reutiliza el mismo render visual y los mismos assets del PDF general para poder revisar el cierre en navegador antes de generar el PDF real.

La preview añade CSS exclusivo de navegador para centrar y separar las páginas y neutralizar `page-break` de impresión. Ese CSS no se envía a Dompdf y no afecta al PDF descargado.

La preview es una ayuda de QA, no sustituye la validación final con un PDF real.

## Stripe

### Variables requeridas

- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_SUCCESS_URL`
- `STRIPE_CANCEL_URL`

### Flujo

- El checkout se inicia desde el backend autenticado.
- La activación del tier no depende de la URL de éxito.
- La activación real se confirma por webhook.
- Begreen guarda referencias y enlaces de factura de Stripe:
  - checkout session
  - payment intent
  - invoice id
  - customer id
  - hosted invoice URL
  - invoice PDF URL

### Eventos mínimos contemplados

- `checkout.session.completed`
- `checkout.session.expired`
- `payment_intent.payment_failed`

### Pruebas recomendadas en modo test

1. Configurar variables de Stripe en `.env.local` o el env del entorno de pruebas.
2. Configurar `stripePriceId` en los planes `Standard` y `Pro` de cada fase desde Super Admin o fixtures de prueba.
3. Lanzar un checkout de prueba desde un proyecto `Basic` o `Standard` indicando fase comercial.
4. Confirmar que el webhook activa el tier correcto solo en la fase comercial correspondiente.
5. Confirmar que se guardan factura y enlaces.
6. Repetir el evento para comprobar idempotencia básica.

## Rutas principales

- `POST /backend/project/{id}/subscription/{phase}/checkout/{targetTier}`
- `GET /backend/project/{id}/subscription/{phase}/success/{targetTier}`
- `GET /backend/project/{id}/subscription/{phase}/cancel/{targetTier}`
- `POST /webhooks/stripe`
- `GET /backend/plan/closure/preview`
- `GET /backend/plan/{id}/export/{grouping}/pdf`
- `GET /backend/plan/{id}/export/{grouping}/excel`

## Validaciones ejecutadas

- `docker compose -f docker-compose.yml -f docker-compose.dev.yml config`
- `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`
- `php -v`
- `composer install --no-interaction --prefer-dist`
- `npm ci --no-audit --no-fund`
- `npm run build`
- `php bin/console about`
- `php bin/console debug:router`
- `php bin/console debug:router backend_plan_closure_preview`
- `php bin/console doctrine:schema:update --dump-sql`
- `./vendor/bin/phpunit`
- `php bin/console lint:twig templates`
- `php bin/console lint:yaml config translations`
- `php -l` sobre los PHP tocados

Nota de estado de Doctrine:

- `doctrine:schema:validate` deja el `Mapping` en `OK`.
- Sigue apareciendo un drift residual en `schema:update --dump-sql` / `Database schema is not in sync`, pero tras revisar la base tabla por tabla no se detectaron columnas críticas ausentes, incluido `project_subscription` y los campos de Stripe.
- Este drift no bloquea la QA funcional de la fase y solo merece revisión si aparece una columna realmente ausente, un nuevo error de mapping, un fallo funcional o un bloqueo de staging/prod.

## Preparación de despliegue

Antes de subir a producción:

1. Hacer backup de la base de datos.
2. Verificar variables de entorno de producción.
3. Confirmar `APP_ENV=prod` y `APP_SECRET`.
4. Configurar `DATABASE_URL`.
5. Configurar claves Stripe y Price IDs live.
6. Registrar el webhook de Stripe en el dashboard.
7. Ejecutar `composer install` en entorno de build.
8. Construir assets.
9. Limpiar y calentar caché.
10. Revisar `doctrine:schema:update --dump-sql`.
11. Aplicar cambios de esquema solo si están revisados y son esperados.
12. Comprobar permisos de subida para evidencias.
13. Verificar login y acceso a un proyecto `Basic`.
14. Revisar la preview HTML de cierre.
15. Generar y revisar un PDF real, incluyendo portada, logos, categorías compartidas, observaciones según tier y cierre.
16. Probar una subida de checkout Stripe en entorno de staging.
17. Revisar logs tras el despliegue.

## Pendientes conocidos

- No hay certificación formal ni auditoría legal.
- No hay workflow formal de aprobación.
- No hay permisos avanzados por responsable.
- No hay notificaciones.
- No hay portal de cliente Stripe.
- No hay facturación propia en Begreen.
- No hay ZIP de exportaciones.
- No hay maquetación avanzada para los PDFs agrupados.
- La autocorrección IA de observaciones no forma parte del flujo actual; su posible implementación queda para una tarea posterior.
- La revisión/edición final asistida del plan antes del cierre queda para una tarea posterior.
- Hay deuda previa en `doctrine:schema:update --dump-sql` que no pertenece a esta fase.

## Fuera de alcance

- Calculadora de emisiones.
- Informe final.
- Stripe Billing recurrente.
- Cupones e impuestos complejos.
- Branding configurable por proyecto/tier. Los logos de empresas participantes ya soportados en la portada del PDF son una capacidad distinta y no implican que la feature comercial de branding esté implementada.
- Gaming adicional.
- Refactors grandes de controladores.
