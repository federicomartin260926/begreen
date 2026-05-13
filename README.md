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

## Documentación de la app

La documentación funcional y técnica específica de Symfony está en:

- [app/README.md](app/README.md)

