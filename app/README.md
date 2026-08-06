# Begreenmyfriend

Plataforma SaaS para gestión de sostenibilidad, cálculo de huella de carbono, planes de mejora y reporting operativo.

## Estado actual

La aplicación Symfony está operativa y cubre, entre otros, estos ámbitos:

- gestión de proyectos y wizard de alta;
- Home/dashboard y proyecto activo por sesión;
- elaboración e implementación del plan de sostenibilidad como fases diferenciadas;
- medidas, seguimiento, evidencias y exportaciones según el plan comercial;
- planes comerciales por fase, Stripe Checkout y facturación de proyecto;
- cálculo y gestión de emisiones;
- administración de catálogos, planes y usuarios;
- acceso a proyectos mediante membresías de usuario.

Los detalles funcionales, límites y trabajo pendiente se documentan en los documentos enlazados a continuación. El modelo actual de usuarios no incluye todavía organizaciones/equipos, roles efectivos por proyecto ni invitaciones de colaboradores.

## Documentación funcional y técnica

### Arquitectura y entorno

- Este README: entorno local, Docker, comandos y depuración.
- [Emails del proyecto](../docs/emails.md): configuración y flujos de correo.

### Plan de sostenibilidad

- [Modelo funcional de estados del plan](../docs/sustainability-plan-status-model.md): elaboración, implementación, verificación e incidencias.
- [Importación de la plantilla de medidas](../docs/measure-template-import.md): formato, validación y aplicación del catálogo.
- [QA y despliegue del plan de sostenibilidad](../docs/sustainability-plan-qa-and-deploy.md): cierre técnico histórico y checklist de la fase.

### Planes comerciales y pagos

- [Tiers comerciales](../docs/commercial-tiers.md): acceso y reglas por nivel.
- [Funciones de planes comerciales](../docs/commercial-plan-features.md): inventario de funcionalidades visibles.
- [Comparativas de planes comerciales](../docs/commercial-plan-comparisons.md): criterios de comparación.
- [Stripe Payments](../docs/stripe-payments.md): Checkout, suscripciones de proyecto y facturación.

### Usuarios, roles y permisos

- [Usuarios, membresías de proyecto e invitaciones](../docs/users-project-memberships-and-invitations.md): modelo actual, limitaciones y alternativas de colaboración.

## Stack técnico

- Symfony 7.3
- PHP 8.3 FPM
- Doctrine ORM + DBAL
- MariaDB 11.3
- Twig
- Bootstrap 5 + Stimulus + Webpack Encore
- Gedmo / Stof Doctrine Extensions para traducciones
- Dompdf para PDF
- PhpSpreadsheet para importaciones y exportaciones

## Estructura del repositorio

El repositorio raíz contiene el Makefile y el envoltorio de despliegue. La aplicación Symfony vive en `app/`.

- `app/src/`: dominio, controladores, formularios, servicios y seguridad.
- `app/config/`: configuración Symfony.
- `app/templates/`: vistas Twig.
- `app/assets/`: Bootstrap, Stimulus y recursos frontend.
- `app/translations/`: mensajes ES/EN.
- `app/public/`: frontend público, assets compilados y subidas.
- `app/docker/legacy/`: stack Docker anterior conservado como referencia.
- `docs/`: documentación funcional y técnica especializada.

## Entornos Docker

El flujo oficial se ejecuta desde la raíz del repositorio mediante el `Makefile`. Este combina:

- `app/docker-compose.yml`: servicios base.
- `app/docker-compose.dev.yml`: desarrollo local.
- `app/docker-compose.prod.yml`: producción.

Servicios principales:

- `begreen-php`: PHP 8.3 FPM.
- `begreen-nginx`: Nginx sirviendo `app/public/`.
- `begreen-db`: MariaDB 11.3.
- `begreen-phpmyadmin`: solo desarrollo.
- `begreen-mailpit`: solo desarrollo.

Desarrollo usa `app/.env` y `app/.env.local`; producción usa `app/.env` y `app/.env.prod`.

## Desarrollo local

Desde la raíz del repositorio:

```bash
make up
```

Comandos habituales:

```bash
make down
make ps
make logs
make shell
make console ARGS='about'
make composer-install
make assets-install
make assets-build
make cache-clear
make schema-update
make fixtures
make test
make doctor
```

URLs locales por defecto:

- Aplicación: `http://127.0.0.1:8080`
- phpMyAdmin: `http://127.0.0.1:8081`
- Mailpit: `http://127.0.0.1:8025`
- MariaDB: `127.0.0.1:3307`

Los puertos pueden ajustarse mediante `APP_HTTP_PORT`, `APP_DB_PORT`, `APP_PMA_PORT`, `APP_MAILPIT_SMTP_PORT` y `APP_MAILPIT_WEB_PORT` en `app/.env.local`.

### Fixtures y esquema

En desarrollo, el flujo de esquema oficial es `doctrine:schema:update`:

```bash
make schema-update
make fixtures
```

Para crear un dump de la base local cargada con fixtures:

```bash
make db-dump-fixtures
```

`make fixtures` reemplaza los datos de la base de desarrollo. No debe ejecutarse directamente en producción.

### Assets y pruebas

El frontend usa npm y Webpack Encore:

```bash
make assets-install
make assets-build
```

La suite PHPUnit se ejecuta con:

```bash
make test
```

## Prueba de conexión con el proveedor de IA

El comando `app:ai:test-report` verifica la configuración, autenticación, conexión con el proveedor configurado, generación estructurada y validación y parseo de la respuesta. Opera a través de `AiReportProviderInterface`, por lo que no está ligado conceptualmente a OpenAI ni a Anthropic, y usa exclusivamente datos ficticios sin persistirlos ni escribir archivos.

`AI_PROVIDER` admite `openai` o `anthropic`. Cada proveedor requiere configurar en `app/.env.local` sus variables privadas (`AI_API_KEY` y `AI_MODEL` para OpenAI; `ANTHROPIC_API_KEY` y `ANTHROPIC_MODEL` para Anthropic). `ANTHROPIC_MODEL` no tiene valor por defecto y debe definirse antes de seleccionar Anthropic. No se debe versionar ninguna clave. El comando prueba el proveedor seleccionado realizando una llamada real, por lo que consume saldo o cuota.

Para ejecutarlo en local con una configuración de proveedor válida:

```bash
docker compose -p begreen \
  --env-file app/.env \
  --env-file app/.env.local \
  -f app/docker-compose.yml \
  -f app/docker-compose.dev.yml \
  exec -T php \
  php bin/console app:ai:test-report
```

## Generación manual del informe narrativo de IA

El comando `app:ai:generate-plan-report` genera o reutiliza el informe narrativo de Elaboración del plan asociado a un proyecto existente. Es una herramienta de diagnóstico y prueba manual: no sustituye al flujo normal de generación de PDF.

Sintaxis:

```bash
php bin/console app:ai:generate-plan-report {projectId} [--locale=es]
```

`projectId` es obligatorio. El comando resuelve el plan de Elaboración asociado al proyecto. La opción `--locale` usa `es` por defecto y admite actualmente `es` y `en`.

Ejemplos:

```bash
# Informe en español
docker compose -p begreen \
  --env-file app/.env \
  --env-file app/.env.local \
  -f app/docker-compose.yml \
  -f app/docker-compose.dev.yml \
  exec -T php \
  php bin/console app:ai:generate-plan-report 123 --locale=es

# Informe en inglés
docker compose -p begreen \
  --env-file app/.env \
  --env-file app/.env.local \
  -f app/docker-compose.yml \
  -f app/docker-compose.dev.yml \
  exec -T php \
  php bin/console app:ai:generate-plan-report 123 --locale=en
```

El informe se reutiliza mientras su JSON continúe vigente; si el contexto de Elaboración, el proveedor, el modelo o la versión del prompt cambian, se regenera. El archivo privado se guarda en:

```text
var/storage/ai/{planId}/{locale}.json
```

Si no existe un informe vigente, el comando puede llamar al proveedor real y consumir saldo o cuota. No lo ejecutes para comprobaciones que no requieran una generación real.

## Despliegue

Los comandos de producción se ejecutan desde la raíz:

```bash
make up-prod
make up-prod-build
make deploy-prod
make deploy-prod-build
make ps-prod
```

`deploy-prod` y `deploy-prod-build` actualizan el código, instalan dependencias de producción, compilan assets, limpian caché y muestran el SQL pendiente de Doctrine. No aplican el esquema automáticamente. `deploy-prod-full` sí ejecuta la actualización de esquema y debe utilizarse únicamente tras revisar el cambio esperado.

Durante la fase de desarrollo, el procedimiento de reemplazo de datos por fixtures requiere una decisión explícita porque sobrescribe la base de producción. Los comandos disponibles son `make db-backup-prod` y `make db-import-prod DUMP=backups/archivo.sql`.

## Proyecto activo

El backend trabaja con un proyecto activo almacenado en la sesión Symfony (`active_project_id`). Si no existe uno válido, `ActiveProjectSubscriber` selecciona el primer proyecto accesible para el usuario. Para usuarios normales, el acceso se determina mediante sus membresías de proyecto; Administradores ven el listado completo.

## Xdebug

La depuración PHP está habilitada en desarrollo al reconstruir la imagen:

```bash
make up-build
```

La configuración de VS Code está en [`.vscode/launch.json`](.vscode/launch.json). Abre el workspace desde la raíz y utiliza los perfiles `Listen for Xdebug (CLI)` o `Listen for Xdebug (Web)`.

## Seguridad y configuración

- No incluir secretos reales en Git.
- Usar `app/.env.local` para overrides locales y `app/.env.prod` para producción.
- `app/.env.example` contiene únicamente valores de ejemplo.
- Las variables y el flujo de Stripe están documentados en [Stripe Payments](../docs/stripe-payments.md).
