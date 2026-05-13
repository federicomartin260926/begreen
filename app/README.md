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

## Cómo levantar el entorno

Desde la raíz de `app/`:

```bash
make up
```

Para producción local:

```bash
make up-prod
```

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

- La fase técnica actual se apoya en `PLANTILLA_PS_v23.xlsx`.
- El flujo de lectura y validación está documentado en [docs/be-green-my-film-v23-import.md](../docs/be-green-my-film-v23-import.md).

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
