# =============================================================================
# Begreen - Root Makefile
# =============================================================================
# Ejecutar siempre desde la raíz del repo:
#   make up
#   make dev-update
#   make deploy-prod
#
# DEV usa:
#   app/.env
#   app/.env.local
#
# PROD usa:
#   app/.env
#   app/.env.prod
#
# Nota:
# - El Makefile está en raíz, pero los compose siguen en app/.
# - Se fuerza PROJECT_NAME=app para mantener nombres/volúmenes históricos.
# =============================================================================

PROJECT_NAME := begreen

COMPOSE_DEV := docker compose -p $(PROJECT_NAME) --env-file app/.env --env-file app/.env.local -f app/docker-compose.yml -f app/docker-compose.dev.yml
COMPOSE_PROD := docker compose -p $(PROJECT_NAME) --env-file app/.env --env-file app/.env.prod -f app/docker-compose.yml -f app/docker-compose.prod.yml

PHP_DEV := $(COMPOSE_DEV) exec php
PHP_PROD := $(COMPOSE_PROD) exec php

.PHONY: \
	help \
	up up-build down restart ps logs shell php console composer-install assets-install assets-build cache-clear schema-dump schema-update fixtures test doctor prepare-private-storage \
	dev-pull dev-update dev-update-build dev-reset-fixtures \
	up-prod up-prod-build prod-up down-prod restart-prod prod-restart ps-prod logs-prod prod-logs shell-prod php-prod console-prod prod-console composer-install-prod assets-install-prod assets-build-prod cache-clear-prod prod-cache-clear schema-dump-prod schema-update-prod prod-schema-update prepare-private-storage-prod \
	prod-pull deploy-prod deploy-prod-build deploy-prod-full

# =============================================================================
# HELP
# =============================================================================

help:
	@echo ""
	@echo "Begreen Makefile"
	@echo "======================================================================"
	@echo ""
	@echo "DEV - uso común"
	@echo "  make up                         Levanta dev sin rebuild"
	@echo "  make up-build                   Levanta dev reconstruyendo imágenes"
	@echo "  make dev-update                 git pull + up + composer install + assets build + cache clear"
	@echo "  make dev-update-build           git pull + up-build + composer install + assets build + cache clear"
	@echo "  make dev-reset-fixtures         git pull + up + schema update + fixtures"
	@echo ""
	@echo "DEV - utilidades"
	@echo "  make down                       Baja dev"
	@echo "  make restart                    Reinicia dev"
	@echo "  make ps                         Lista contenedores dev"
	@echo "  make logs                       Logs dev"
	@echo "  make shell                      Shell dentro del contenedor php dev"
	@echo "  make console ARGS='cache:clear' Ejecuta bin/console en dev"
	@echo "  make composer-install           composer install en dev"
	@echo "  make assets-install             npm ci en dev"
	@echo "  make assets-build               npm run build en dev"
	@echo "  make cache-clear                Limpia cache dev"
	@echo "  make schema-dump                Muestra SQL de schema:update en dev"
	@echo "  make schema-update              Aplica doctrine:schema:update --force en dev"
	@echo "  make fixtures                   Recarga fixtures dev"
	@echo "  make test                       Ejecuta PHPUnit dev"
	@echo "  make doctor                     Diagnóstico rápido dev"
	@echo ""
	@echo "PROD - uso común"
	@echo "  make up-prod                    Levanta prod sin rebuild"
	@echo "  make up-prod-build              Levanta prod reconstruyendo imágenes"
	@echo "  make deploy-prod                git pull + up-prod + composer prod + assets + cache + schema dump"
	@echo "  make deploy-prod-build          git pull + up-prod-build + composer prod + assets + cache + schema dump"
	@echo "  make deploy-prod-full           Igual que deploy-prod-build, pero también ejecuta schema-update-prod"
	@echo ""
	@echo "PROD - utilidades"
	@echo "  make down-prod                  Baja prod"
	@echo "  make restart-prod               Reinicia prod"
	@echo "  make ps-prod                    Lista contenedores prod"
	@echo "  make logs-prod                  Logs prod"
	@echo "  make shell-prod                 Shell dentro del contenedor php prod"
	@echo "  make console-prod ARGS='...'    Ejecuta bin/console en prod"
	@echo "  make composer-install-prod      composer install --no-dev en prod"
	@echo "  make assets-install-prod        npm ci en prod"
	@echo "  make assets-build-prod          npm run build en prod"
	@echo "  make cache-clear-prod           Limpia cache prod"
	@echo "  make schema-dump-prod           Muestra SQL de schema:update en prod"
	@echo "  make schema-update-prod         Aplica doctrine:schema:update --force en prod"
	@echo ""
	@echo "Entornos usados:"
	@echo "  DEV  -> app/.env + app/.env.local"
	@echo "  PROD -> app/.env + app/.env.prod"
	@echo ""
	@echo "Mailpit local:"
	@echo "  Configurar en app/.env.local:"
	@echo "    APP_MAILPIT_SMTP_PORT=1027"
	@echo "    APP_MAILPIT_WEB_PORT=8027"
	@echo "  UI: http://127.0.0.1:8027"
	@echo ""

# =============================================================================
# DEV COMMANDS
# =============================================================================

up:
	$(COMPOSE_DEV) up -d
	$(MAKE) prepare-private-storage

up-build:
	$(COMPOSE_DEV) up -d --build
	$(MAKE) prepare-private-storage

down:
	$(COMPOSE_DEV) down

restart:
	$(COMPOSE_DEV) restart

ps:
	$(COMPOSE_DEV) ps

logs:
	$(COMPOSE_DEV) logs -f --tail=100

shell:
	$(PHP_DEV) sh

php: shell

console:
	$(PHP_DEV) php bin/console $(ARGS)

composer-install:
	$(PHP_DEV) composer install --no-interaction

assets-install:
	$(PHP_DEV) npm ci --no-audit --no-fund

assets-build:
	$(PHP_DEV) npm run build

cache-clear:
	$(PHP_DEV) php bin/console cache:clear

schema-dump:
	$(PHP_DEV) php bin/console doctrine:schema:update --dump-sql

schema-update:
	$(PHP_DEV) php bin/console doctrine:schema:update --force

fixtures:
	$(PHP_DEV) php bin/console doctrine:fixtures:load --no-interaction

test:
	$(PHP_DEV) ./vendor/bin/phpunit

doctor:
	$(COMPOSE_DEV) ps
	$(PHP_DEV) php -v
	$(PHP_DEV) php -m
	$(PHP_DEV) php bin/console about
	$(PHP_DEV) php bin/console doctrine:schema:update --dump-sql
	$(PHP_DEV) ./vendor/bin/phpunit --list-tests

prepare-private-storage:
	$(COMPOSE_DEV) exec -u root php sh -lc 'mkdir -p /app/var/private/stripe-invoices && chown -R www-data:www-data /app/var/private && chmod -R u+rwX,g+rwX /app/var/private'

# =============================================================================
# DEV WORKFLOWS
# =============================================================================

dev-pull:
	git pull

dev-update:
	git pull
	$(MAKE) up
	$(MAKE) composer-install
	$(MAKE) assets-build
	$(MAKE) cache-clear

dev-update-build:
	git pull
	$(MAKE) up-build
	$(MAKE) composer-install
	$(MAKE) assets-build
	$(MAKE) cache-clear

dev-reset-fixtures:
	git pull
	$(MAKE) up
	$(MAKE) composer-install
	$(MAKE) assets-build
	$(MAKE) cache-clear
	$(MAKE) schema-update
	$(MAKE) fixtures

# =============================================================================
# PROD COMMANDS
# =============================================================================

up-prod:
	$(COMPOSE_PROD) up -d
	$(MAKE) prepare-private-storage-prod

up-prod-build:
	$(COMPOSE_PROD) up -d --build
	$(MAKE) prepare-private-storage-prod

prod-up: up-prod

down-prod:
	$(COMPOSE_PROD) down

restart-prod:
	$(COMPOSE_PROD) restart

prod-restart: restart-prod

ps-prod:
	$(COMPOSE_PROD) ps

logs-prod:
	$(COMPOSE_PROD) logs -f --tail=100

prod-logs: logs-prod

shell-prod:
	$(PHP_PROD) sh

php-prod: shell-prod

console-prod:
	$(PHP_PROD) php bin/console $(ARGS)

prod-console: console-prod

composer-install-prod:
	$(PHP_PROD) composer install --no-interaction --no-dev --optimize-autoloader

assets-install-prod:
	$(PHP_PROD) npm ci --no-audit --no-fund

assets-build-prod:
	$(PHP_PROD) npm run build

cache-clear-prod:
	$(PHP_PROD) php bin/console cache:clear --env=prod

prod-cache-clear: cache-clear-prod

schema-dump-prod:
	$(PHP_PROD) php bin/console doctrine:schema:update --dump-sql --env=prod

schema-update-prod:
	$(PHP_PROD) php bin/console doctrine:schema:update --force --env=prod

prod-schema-update:
	$(PHP_PROD) php bin/console doctrine:schema:update --dump-sql --env=prod

prepare-private-storage-prod:
	$(COMPOSE_PROD) exec -u root php sh -lc 'mkdir -p /app/var/private/stripe-invoices && chown -R www-data:www-data /app/var/private && chmod -R u+rwX,g+rwX /app/var/private'

# =============================================================================
# PROD WORKFLOWS
# =============================================================================

prod-pull:
	git pull

deploy-prod:
	git pull
	$(MAKE) up-prod
	$(MAKE) composer-install-prod
	$(MAKE) assets-build-prod
	$(MAKE) cache-clear-prod
	$(MAKE) schema-dump-prod

deploy-prod-build:
	git pull
	$(MAKE) up-prod-build
	$(MAKE) composer-install-prod
	$(MAKE) assets-build-prod
	$(MAKE) cache-clear-prod
	$(MAKE) schema-dump-prod

deploy-prod-full:
	git pull
	$(MAKE) up-prod-build
	$(MAKE) composer-install-prod
	$(MAKE) assets-build-prod
	$(MAKE) cache-clear-prod
	$(MAKE) schema-update-prod