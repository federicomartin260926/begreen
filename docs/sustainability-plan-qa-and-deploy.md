# Sustainability Plan QA and Deploy

Documento de cierre técnico de la fase **Plan de Sostenibilidad Be Green My Film**.

## Alcance cerrado

Esta fase deja operativos estos bloques:

- Catálogo Be Green My Film v23 editable desde admin.
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
- El nivel de compromiso se calcula sobre el catálogo oficial v23, sin mezclar las medidas custom en el total oficial.

## Stripe

### Variables requeridas

- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_STANDARD_PRICE_ID`
- `STRIPE_PRO_PRICE_ID`
- `STRIPE_UPGRADE_STANDARD_TO_PRO_PRICE_ID`
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
2. Crear Price IDs en Stripe Dashboard.
3. Lanzar un checkout de prueba desde un proyecto `Basic` o `Standard`.
4. Confirmar que el webhook activa el tier correcto.
5. Confirmar que se guardan factura y enlaces.
6. Repetir el evento para comprobar idempotencia básica.

## Rutas principales

- `POST /backend/project/{id}/subscription/checkout/{targetTier}`
- `GET /backend/project/{id}/subscription/success`
- `GET /backend/project/{id}/subscription/cancel`
- `POST /webhooks/stripe`
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
- `php bin/console doctrine:schema:update --dump-sql`
- `./vendor/bin/phpunit`
- `php bin/console lint:twig templates`
- `php bin/console lint:yaml config translations`
- `php -l` sobre los PHP tocados

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
14. Probar una subida de checkout Stripe en entorno de staging.
15. Revisar logs tras el despliegue.

## Pendientes conocidos

- No hay certificación formal ni auditoría legal.
- No hay workflow formal de aprobación.
- No hay permisos avanzados por responsable.
- No hay notificaciones.
- No hay portal de cliente Stripe.
- No hay facturación propia en Begreen.
- No hay ZIP de exportaciones.
- No hay maquetación avanzada para los PDFs agrupados.
- Hay deuda previa en `doctrine:schema:update --dump-sql` que no pertenece a esta fase.

## Fuera de alcance

- Calculadora de emisiones.
- Informe final.
- Stripe Billing recurrente.
- Cupones e impuestos complejos.
- Branding por proyecto.
- Gaming adicional.
- Refactors grandes de controladores.

