# Begreenmyfriend

Plataforma Symfony para gestión de sostenibilidad, planes de mejora, cálculo de huella y reporting operativo.

## Arquitectura

El repositorio raíz actúa como envoltorio de despliegue Docker y contiene la aplicación Symfony dentro de `app/`.

- `docker-compose.yml`: stack base de despliegue local
- `Dockerfile`: runtime PHP para el contenedor `php`
- `nginx/`: configuración del proxy web
- `app/`: código fuente Symfony, plantillas, assets, fixtures y documentación de la aplicación

## Tech stack

- PHP 8.3 FPM
- Symfony
- Doctrine ORM / DBAL
- MariaDB 11.3
- Nginx
- Twig
- Stimulus y Webpack Encore
- Dompdf
- PhpSpreadsheet
- Gedmo / Stof Doctrine Extensions

## Entorno Docker

El proyecto se ejecuta con Docker desde la raíz del repositorio.

Servicios del `docker-compose.yml` de raíz:

- `php`: runtime PHP-FPM
- `nginx`: servidor web
- `mariadb`: base de datos
- `phpmyadmin`: administración local

Puertos por defecto:

- Aplicación: `http://127.0.0.1:8080`
- phpMyAdmin: `http://127.0.0.1:8081`
- MariaDB: `127.0.0.1:3307`

## Cómo arrancar

Desde la raíz:

```bash
docker compose up -d --build
```

Parar servicios:

```bash
docker compose down
```

## Comandos útiles

El flujo habitual de la aplicación Symfony se ejecuta desde el `Makefile` de raíz:

```bash
make console ARGS='app:seed-sustainability-plan 123 25'
```

Ese comando crea un plan de sostenibilidad de prueba para el proyecto `123` con hasta `25` medidas aleatorias del plan comercial asignado. Si omites el segundo argumento, usa todas las medidas disponibles del plan comercial del proyecto.

Otros ejemplos frecuentes:

```bash
make console ARGS='app:seed-sustainability-plan 123'
make console ARGS='app:import:be-green-my-film-v23 /ruta/al/archivo.xlsx --dry-run'
make console ARGS='app:build-measure-fixture-from-v31 public/fixtures/be_green_my_film_measures.xlsx'
make console ARGS='app:extract-measure-template-v31 public/fixtures/be_green_my_film_measures.xlsx'
make console ARGS='app:send-test-email test@example.com'
```

Para operaciones recurrentes también existen estos targets:

```bash
make up
make down
make assets-build
make schema-update
make fixtures
make test
```

## Documentación de la app

La documentación funcional y técnica específica de Symfony está en:

- [app/README.md](app/README.md)
- [docs/measure-template-import.md](docs/measure-template-import.md)
- [docs/emails.md](docs/emails.md)
- La depuración con Xdebug para VS Code está documentada en [app/README.md](app/README.md#xdebug)
