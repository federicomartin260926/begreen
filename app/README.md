# Begreenmyfriend

Plataforma SaaS para gestión de sostenibilidad, cálculo de huella de carbono, planes de mejora y reporting operativo.

## Stack técnico

- Symfony 7.3
- PHP 8.3 FPM
- Doctrine ORM + DBAL
- MariaDB 11.3
- Twig
- Bootstrap 5 + Stimulus + Webpack Encore
- Gedmo / Stof Doctrine Extensions para traducciones
- Dompdf para PDF
- PhpSpreadsheet para importaciones/exportaciones

## Estructura del repositorio

- `src/`: dominio, controladores, formularios, servicios, seguridad
- `config/`: configuración Symfony
- `templates/`: vistas Twig
- `assets/`: Bootstrap, Stimulus y recursos frontend
- `translations/`: mensajes ES/EN
- `public/`: frontend público, assets compilados y subidas
- `docker/legacy/`: stack Docker anterior, conservado como referencia
- los antiguos `compose.yaml` y `compose.override.yaml` han sido archivados en `docker/legacy/`
- este repositorio es autosuficiente y no depende de redes Docker externas ni de otros proyectos del workspace

## Entornos Docker

El stack oficial de esta fase usa MariaDB como base de datos y se separa en:

- `docker-compose.yml`: base común
- `docker-compose.dev.yml`: desarrollo local
- `docker-compose.prod.yml`: producción

Servicios:

- `begreen-php`: PHP 8.3 FPM
- `begreen-nginx`: Nginx sirviendo `public/`
- `begreen-db`: MariaDB 11.3
- `begreen-phpmyadmin`: solo dev
- `begreen-mailpit`: solo dev

No existe dependencia operativa de `proxy`, Nginx Proxy Manager ni de servicios compartidos con otros repositorios. Si se coloca un reverse proxy externo, se hace fuera de este compose y sin ser requisito del proyecto.

Para un despliegue de producción en VPS, el compose `docker-compose.prod.yml` conecta `begreen-nginx` a una red Docker externa `proxy` y monta `app/.env.local` en el contenedor PHP en modo lectura. Eso permite usar Nginx Proxy Manager o un proxy equivalente sin publicar puertos del backend al host.

## Cómo levantar el entorno

Desde la raíz de `app/`:

```bash
make up
```

Para producción local:

```bash
make up-prod
```

En VPS, si usas Nginx Proxy Manager como en otros proyectos, crea primero la red Docker externa `proxy` y apunta el host a `begreen-nginx:80` dentro de esa red. El fichero `.env.local` debe existir en `app/` con los valores de producción reales.

Parar servicios:

```bash
make down
```

Logs:

```bash
make logs
```

Entrar al contenedor PHP:

```bash
make php
```

## Comandos útiles

Instalar dependencias PHP:

```bash
make composer-install
```

Instalar dependencias frontend:

```bash
make assets-install
```

Compilar assets:

```bash
make assets-build
```

Instalación frontend determinista:

```bash
make assets-install
```

Limpiar caché:

```bash
make cache-clear
```

Ejecutar Symfony Console:

```bash
make console ARGS=about
```

Actualizar esquema Doctrine:

```bash
make schema-update
```

Cargar fixtures:

```bash
make fixtures
```

Ejecutar tests:

```bash
make test
```

Diagnóstico básico:

```bash
make doctor
```

## URLs locales

En desarrollo:

- Aplicación: `http://127.0.0.1:18080`
- phpMyAdmin: `http://127.0.0.1:8081`
- Mailpit: `http://127.0.0.1:8025`

Base de datos:

- Host interno Docker: `db:3306`
- Puerto expuesto local: `127.0.0.1:3307`

Si esos puertos están ocupados, ajusta `APP_HTTP_PORT`, `APP_DB_PORT`, `APP_PMA_PORT`, `APP_MAILPIT_SMTP_PORT` y `APP_MAILPIT_WEB_PORT` antes de levantar el stack.

Ejemplo de `DATABASE_URL`:

```env
DATABASE_URL="mysql://begreen:begreen@db:3306/begreenmyfriend?serverVersion=11.3&charset=utf8mb4"
```

## Doctrine

En esta fase el flujo oficial sigue siendo `doctrine:schema:update`.

No se ha convertido el proyecto a un flujo de migraciones como parte de este saneamiento.

## Multiidioma

- Idiomas: ES / EN
- Configuración basada en Symfony Translator
- Traducciones de entidades con Gedmo + Stof Doctrine Extensions

## Importación de medidas

- La fase técnica actual se apoya en la plantilla estándar de medidas.
- El flujo de lectura, validación y `--apply` está documentado en [docs/measure-template-import.md](../docs/measure-template-import.md).
- La importación es idempotente por `protocol + sourceRow`, con `importHash` como trazabilidad adicional.
- El admin de medidas ya permite revisar y ajustar manualmente el catálogo importado, incluyendo taxonomías múltiples y fuentes de verificación por prioridad.
- En el flujo del plan, el protocolo canónico de esta fase consume solo las medidas activas definidas para el plan; las medidas legacy del mismo protocolo quedan excluidas del recorrido canónico.
- El review/listado del plan ya expone filtros básicos por departamento, ODS, área de impacto, triple balance y alcance, usando taxonomías múltiples cuando existen.
- El orden de las medidas cambia según la vista:
  - en la vista operativa de creación del plan se ordenan por `categoría` o `departamento` según el `groupingBy` del protocolo, después por bloque, y finalmente por `sort_order` de la medida;
  - cuando el protocolo agrupa por departamento, la medida usa como departamento de agrupación el `measure.department` singular; si la medida no tiene departamento, cae en el grupo `Sin departamento` y queda al final del orden de departamentos;
  - en la vista tabular/review, ya con el plan creado, se ordenan por estado del plan de la medida (`rank`) y después por `nameReview` si existe, o por `name`;
  - en las exportaciones agrupadas, el agrupado se hace por la taxonomía elegida y dentro de cada grupo se ordena por nombre visible (`displayName`).
- Las exportaciones del plan ya siguen el modelo Basic / Standard / Pro: Basic solo descarga el PDF unificado, Standard añade PDF agrupado por departamentos y Pro añade PDF/Excel agrupados por categorías, departamentos, áreas de impacto, triple balance y ODS.
- La capa Pro de colaboración añade comentarios visibles, notas internas, responsables por medida, resumen de validación y medidas personalizadas de proyecto.
- El review del plan también muestra niveles de compromiso basados en puntos oficiales del catálogo de medidas:
  - compromiso previsto desde las medidas marcadas para implementar;
  - compromiso real desde las medidas ya implementadas;
  - niveles: Semilla, Planta, Árbol, Bosque y Selva;
  - cálculo por puntos oficiales, no por número de medidas;
  - las medidas personalizadas no entran en este nivel;
  - es un indicador motivacional, no una certificación formal.
- La inclusión de medidas por plan comercial es por puntuación, no por IDs fijos:
  - `Basic`: medidas de 4 y 5 puntos;
  - `Standard`: medidas de 3, 4 y 5 puntos;
  - `Pro`: medidas de 1, 2, 3, 4 y 5 puntos.
  - Este filtro se aplica sobre el catálogo oficial visible del protocolo y después de excluir bloques saltados.
  - No existe una lista estática medida-por-medida en la documentación: la fuente de verdad funcional es el catálogo activo + la lógica de resolver el tier.
- Los bloques de medidas son una subclasificación opcional dentro de un protocolo:
  - no son categorías principales ni tienen subbloques;
  - pueden tener una pregunta previa opcional;
  - si se responde "No", el flujo marca automáticamente como `No aplica` las medidas visibles del bloque y deja trazabilidad del salto;
  - si se responde "Sí", las medidas auto-descartadas por ese bloque vuelven a estado pendiente para poder responderse;
  - los bloques descartados no computan en la puntuación máxima aplicable del plan.
  - el campo `Orden` del bloque es interno y no gobierna el recorrido del plan; el recorrido operativo lo determinan las medidas.
- Stripe Checkout ya activa `standard` y `pro` por proyecto con facturas emitidas por Stripe; la integración está documentada en [docs/stripe-payments.md](../docs/stripe-payments.md).

## Tiers comerciales

- Los tiers internos por proyecto son `basic`, `standard` y `pro`.
- El detalle de reglas y bloqueo de funcionalidades está en [docs/commercial-tiers.md](../docs/commercial-tiers.md).
- El inventario funcional de las opciones visibles en la ficha comercial está en [docs/commercial-plan-features.md](../docs/commercial-plan-features.md).
- Los upgrades de pago único por proyecto se gestionan con Stripe Checkout y generan referencias de factura almacenadas en `ProjectSubscription`.
- La suite PHPUnit ahora incluye `tests/Service` además de `Smoke` e `Import`.

## Seguridad

- No commitear secretos reales
- Usar `.env.local` para overrides locales
- `.env.example` contiene solo valores de ejemplo

## Estado de la Fase 0

Normalizado:

- stack Docker oficial unificado
- MariaDB como base de datos oficial
- PHP 8.3 con extensiones necesarias
- Nginx para Symfony
- archivos legacy aislados
- `.env.example` creado
- Makefile operativo

Pendiente:

- validación end-to-end completa en una base nueva
- tests automáticos
- la suite actual es mínima y de tipo smoke
- saneamiento de secretos ya existentes en `.env` y `.env.local`
- refactor funcional de controladores grandes
- ampliar tests de cálculo de emisiones, membresías/seguridad y PDFs
