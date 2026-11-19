# Begreenmyfriend

Plataforma SaaS para gestión de sostenibilidad, cálculo de huella de carbono, planes de mejora y reporting operativo.

## Estado actual

La aplicación Symfony está operativa y cubre, entre otros, estos ámbitos:

- gestión de proyectos y wizard de alta;
- Home/dashboard y proyecto activo por sesión;
- elaboración e implementación del plan de sostenibilidad como fases diferenciadas;
- medidas, planificación, implementación y seguimiento, evidencias y exportaciones según el plan comercial;
- un único plan comercial por proyecto (Basic, Standard o Pro), Stripe Checkout y facturación de proyecto;
- cálculo y gestión de emisiones;
- administración de catálogos, planes y usuarios;
- acceso a proyectos mediante membresías de usuario;
- rol `ROLE_VERIFIER` limitado a los proyectos asignados mediante membresía.

El proyecto continúa en fase de desarrollo. Actualmente existen dos entornos operativos: **LOCAL** y **DEV VPS**. No existe todavía un entorno de producción separado.

Los detalles funcionales, límites y trabajo pendiente se documentan en los documentos enlazados a continuación. El modelo actual no introduce organizaciones/equipos ni un sistema general de invitaciones de colaboradores salvo donde la documentación específica indique lo contrario.

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
- [Stripe Payments](../docs/stripe-payments.md): Checkout y facturación del proyecto.

### Usuarios, roles y permisos

- [Usuarios, membresías de proyecto e invitaciones](../docs/users-project-memberships-and-invitations.md): modelo de acceso y alternativas de colaboración.

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
- `app/public/`: frontend público, assets compilados, fixtures versionados y subidas.
- `app/docker/legacy/`: stack Docker anterior conservado como referencia.
- `docs/`: documentación funcional y técnica especializada.

## Catálogo de medidas

El catálogo canónico actual contiene **637 medidas**:

- Film: 200;
- Event: 200;
- Animation: 237.

Los catálogos versionados se mantienen en `app/public/fixtures/` y son la referencia reproducible para la carga del catálogo.

El modelo actual soporta, entre otros:

- departamentos asociados a cada medida;
- ODS y áreas de impacto;
- Impacto, Dificultad y Coste;
- Planificación ES/EN;
- Implementación y seguimiento ES/EN;
- múltiples fuentes de evidencia por medida;
- calidad sugerida de evidencia;
- metadatos específicos de Animation, incluido el resultado ambiental esperado.

Las columnas técnicas de auditoría y certificación que todavía no estén modeladas en la aplicación permanecen conservadas en las fuentes canónicas hasta que sus requerimientos funcionales se implementen.

## Entornos Docker

El flujo oficial se ejecuta desde la raíz del repositorio mediante el `Makefile`.

### LOCAL

Combina:

- `app/docker-compose.yml`: servicios base;
- `app/docker-compose.dev.yml`: configuración de desarrollo local.

Configuración:

- `app/.env`;
- `app/.env.local` para overrides y secretos no versionados.

### DEV VPS

El entorno remoto de desarrollo añade:

- `app/docker-compose.vps.yml`.

El repositorio remoto está actualmente en:

```text
/home/fede/www/begreen
```

El entorno DEV es el único destino remoto disponible actualmente. No debe asumirse ni utilizarse un entorno PROD hasta que exista y se configure expresamente.

Servicios principales:

- `begreen-php`: PHP 8.3 FPM;
- `begreen-nginx`: Nginx sirviendo `app/public/`;
- `begreen-db`: MariaDB 11.3;
- `begreen-phpmyadmin`: desarrollo;
- `begreen-mailpit`: desarrollo local cuando está habilitado.

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

En esta fase de desarrollo, el flujo oficial de esquema es `doctrine:schema:update`:

```bash
make schema-update
make fixtures
```

`make fixtures` purga y reconstruye la base de datos del entorno en el que se ejecuta. Debe utilizarse únicamente cuando la tarea lo autorice expresamente.

Para crear un dump de la base LOCAL cargada con fixtures:

```bash
make db-dump-fixtures
```

### Protección del catálogo en DEV

Franc puede modificar medidas directamente desde **Administración > Medidas** en DEV. Por ello, antes de cualquier recarga, purge o reset de fixtures en DEV es obligatorio:

1. inspeccionar si existen cambios de catálogo posteriores a la última sincronización conocida;
2. comparar y reconciliar esas diferencias con las fuentes canónicas;
3. incorporar a los fixtures/fuentes los cambios intencionales;
4. detenerse si una diferencia es ambigua;
5. realizar un backup completo y fresco de la base DEV inmediatamente antes de la operación destructiva;
6. ejecutar esquema/fixtures solo tras esa comprobación;
7. verificar después del reload el catálogo y los proyectos demo ECOEMBES y JACOB'S CREEK, incluidos logos y planes comerciales.

La base DEV no es la fuente canónica permanente: una vez reconciliados los cambios intencionales, las fuentes versionadas vuelven a ser la referencia.

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

Por defecto deben preferirse pruebas focales proporcionales al riesgo del cambio frente a ejecutar toda la suite para modificaciones pequeñas.

## IA y generación narrativa

La aplicación dispone de infraestructura para generación narrativa mediante proveedor de IA y de comandos técnicos de diagnóstico/generación.

**Estado operativo actual:** las llamadas reales a proveedores externos de IA están deshabilitadas por decisión de seguridad mientras se mantiene abierta la investigación correspondiente. No deben reactivarse ni ejecutarse comandos que realicen llamadas reales salvo autorización expresa.

Las claves y modelos se configuran exclusivamente mediante variables privadas fuera de Git. Nunca deben versionarse, imprimirse ni incluirse en logs.

Cuando la funcionalidad vuelva a estar autorizada, los comandos existentes podrán utilizarse de acuerdo con su documentación y teniendo en cuenta que una llamada real puede consumir saldo o cuota.

Las instrucciones del informe narrativo se mantienen en `config/ai_report_prompt.yaml` bajo control de versiones. Los cálculos siguen siendo lógica determinista de la aplicación y no deben trasladarse al prompt.

## Despliegue DEV VPS

No existe actualmente un entorno de producción separado. El único despliegue remoto se realiza en **DEV VPS**.

Desde el servidor:

```bash
cd /home/fede/www/begreen || exit 1
make dev-vps-ps
```

Actualización normal de código y dependencias:

```bash
cd /home/fede/www/begreen || exit 1
make dev-vps-update
```

Cuando sea necesario reconstruir imágenes:

```bash
cd /home/fede/www/begreen || exit 1
make dev-vps-update-build
```

Los cambios de schema o fixtures requieren autorización específica y no deben introducirse implícitamente dentro de una actualización ordinaria.

Antes de una operación destructiva de catálogo en DEV deben cumplirse las reglas de **Protección del catálogo en DEV** descritas anteriormente.

Después de cada despliegue debe ejecutarse el QA focal mínimo necesario para validar la funcionalidad afectada.

## Proyecto activo

El backend trabaja con un proyecto activo almacenado en la sesión Symfony (`active_project_id`). Si no existe uno válido, `ActiveProjectSubscriber` selecciona el primer proyecto accesible para el usuario.

- `ROLE_ADMIN` mantiene visibilidad global de proyectos según el contrato actual.
- `ROLE_VERIFIER` está limitado a los proyectos asignados mediante `ProjectMembership`.
- otros usuarios acceden según sus membresías y permisos aplicables.

## Xdebug

La depuración PHP está habilitada en desarrollo al reconstruir la imagen:

```bash
make up-build
```

La configuración de VS Code está en [`.vscode/launch.json`](.vscode/launch.json). Abre el workspace desde la raíz y utiliza los perfiles `Listen for Xdebug (CLI)` o `Listen for Xdebug (Web)`.

## Seguridad y configuración

- No incluir secretos reales en Git.
- Usar `app/.env.local` para overrides y secretos de LOCAL/DEV, manteniéndolo fuera de control de versiones.
- `app/.env.example` contiene únicamente valores de ejemplo.
- No asumir ni configurar `.env.prod` mientras no exista un entorno de producción explícito.
- No desplegar, modificar schema ni recargar fixtures en DEV sin autorización específica de la tarea.
- Las variables y el flujo de Stripe están documentados en [Stripe Payments](../docs/stripe-payments.md).
