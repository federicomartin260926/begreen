# =============================================================================
# Begreen - Root Makefile
# =============================================================================
# Ejecutar desde la raíz del repositorio.
# Entornos disponibles: LOCAL y DEV (VPS). No existe entorno de producción.
# Configuración base: app/.env + app/.env.local
# Compose: app/docker-compose.yml + app/docker-compose.dev.yml
# DEV VPS añade app/docker-compose.vps.yml.
# Se conserva PROJECT_NAME=begreen para respetar nombres/volúmenes existentes.
#
# OPERACIONES DE DATOS
# - En LOCAL, schema-update y fixtures se ejecutan solo cuando la tarea lo autoriza.
# - fixtures purga y reconstruye la base de datos LOCAL; hacer backup primero.
# - En DEV, antes de cualquier recarga: reconciliar ediciones manuales de Franc,
#   hacer backup completo y solicitar autorización expresa para modificar datos.
# - No ejecutar comandos DEV VPS desde LOCAL por accidente.
# =============================================================================

PROJECT_NAME := begreen

COMPOSE_DEV := docker compose -p $(PROJECT_NAME) --env-file app/.env --env-file app/.env.local -f app/docker-compose.yml -f app/docker-compose.dev.yml
COMPOSE_DEV_VPS := docker compose -p $(PROJECT_NAME) --env-file app/.env --env-file app/.env.local -f app/docker-compose.yml -f app/docker-compose.dev.yml -f app/docker-compose.vps.yml

PHP_DEV := $(COMPOSE_DEV) exec php

.PHONY: \
	help \
	up up-build down restart ps logs shell php console composer-install assets-install assets-build cache-clear schema-dump schema-update fixtures db-dump-fixtures test manual-admin-pdf manual-super-admin-pdf manual-pdfs doctor prepare-private-storage \
	dev-pull dev-update dev-update-build dev-reset-fixtures

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
	@echo "  make db-dump-fixtures           Crea dump local de la BD dev actual en backups/"
	@echo "  make test                       Ejecuta PHPUnit dev"
	@echo "  make manual-admin-pdf           Regenera el manual PDF de Administrador"
	@echo "  make manual-super-admin-pdf     Regenera el manual PDF de Super Administrador"
	@echo "  make manual-pdfs                 Regenera los dos manuales PDF"
	@echo "  make doctor                     Diagnóstico rápido dev"
	@echo ""
	@echo "Entornos disponibles: LOCAL y DEV VPS (sin entorno de producción)"
	@echo "  make dev-vps-ps                Estado de contenedores en DEV VPS (ejecutar allí)"
	@echo "  make dev-vps-update            Código DEV sin rebuild; aborta si detecta cambio de mapeo"
	@echo "  make dev-vps-update SCHEMA=1   Backup fresco + schema-update + smoke; sin fixtures"
	@echo "  make dev-vps-update-build      Deshabilitado en DEV (usar volúmenes)"
	@echo "  make dev-vps-catalog-audit SINCE=YYYY-MM-DD_HH:MM:SS (solo DEV VPS, READ-ONLY; usar comillas para fecha con espacio)"
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
	@if [ "$(CURDIR)" = "/home/fede/www/begreen" ] && [ "$(ALLOW_DEV_FIXTURES)" != "YES" ]; then \
	  echo "BLOCKED: DEV fixtures require human audit, a fresh backup and ALLOW_DEV_FIXTURES=YES"; exit 2; fi
	$(PHP_DEV) php bin/console doctrine:fixtures:load --no-interaction

# Fail-closed, unique, private backup; never print DB contents to deployment logs.
db-dump-fixtures:
	@set -eu; umask 077; mkdir -p backups; \
	DUMP=$$(mktemp --suffix=.sql "backups/begreen_clean_fixtures_$$(date +%Y%m%d_%H%M%S)_XXXXXX"); \
	echo "Creating dev DB dump: $$DUMP"; \
	if ! $(COMPOSE_DEV) exec -T db sh -lc 'mariadb-dump -h 127.0.0.1 -u"$$MARIADB_USER" -p"$$MARIADB_PASSWORD" "$$MARIADB_DATABASE" --single-transaction --routines --triggers --events' > "$$DUMP"; then \
	  echo "BACKUP=FAIL"; rm -f "$$DUMP"; exit 1; fi; \
	if [ ! -s "$$DUMP" ]; then echo "BACKUP=EMPTY"; rm -f "$$DUMP"; exit 1; fi; \
	printf 'DB_BACKUP=PASS\nDB_BACKUP_BYTES=%s\n' "$$(wc -c < "$$DUMP")"

test:
	$(PHP_DEV) ./vendor/bin/phpunit

manual-admin-pdf:
	$(COMPOSE_DEV) run --rm --no-deps -v "$(CURDIR)/docs/manuales:/manual-docs:ro" php php bin/generate-user-manual.php /manual-docs/roles/administrador.md /manual-docs resources/manuales/manual-administrador.pdf "Administrador / Consultora"

manual-super-admin-pdf:
	$(COMPOSE_DEV) run --rm --no-deps -v "$(CURDIR)/docs/manuales:/manual-docs:ro" php php bin/generate-user-manual.php /manual-docs/roles/super-administrador.md /manual-docs resources/manuales/manual-super-administrador.pdf "Super Administrador"

manual-pdfs: manual-admin-pdf manual-super-admin-pdf

doctor:
	$(COMPOSE_DEV) ps
	$(PHP_DEV) php -v
	$(PHP_DEV) php -m
	$(PHP_DEV) php bin/console about
	$(PHP_DEV) php bin/console doctrine:schema:update --dump-sql
	$(PHP_DEV) ./vendor/bin/phpunit --list-tests

prepare-private-storage:
	$(COMPOSE_DEV) exec -u root php sh -lc 'mkdir -p /app/var/private/stripe-invoices /app/var/storage/project-documents && chown -R www-data:www-data /app/var/private && chown www-data:www-data /app/var/storage /app/var/storage/project-documents && chmod -R u+rwX,g+rwX /app/var/private && chmod 750 /app/var/storage /app/var/storage/project-documents'

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
	@if [ "$(CURDIR)" = "/home/fede/www/begreen" ]; then \
	  echo "BLOCKED: use the controlled DEV fixtures procedure, never dev-reset-fixtures"; exit 2; fi
	git pull
	$(MAKE) up
	$(MAKE) composer-install
	$(MAKE) assets-build
	$(MAKE) cache-clear
	$(MAKE) schema-update
	$(MAKE) fixtures

# =============================================================================
# SHARED DEV VPS
# =============================================================================

.PHONY: dev-vps-up dev-vps-up-build dev-vps-down dev-vps-ps dev-vps-update dev-vps-update-build

dev-vps-up:
	$(COMPOSE_DEV_VPS) up -d --no-build
	$(MAKE) prepare-private-storage

dev-vps-up-build:
	$(COMPOSE_DEV_VPS) up -d --build
	$(MAKE) prepare-private-storage

dev-vps-down:
	$(COMPOSE_DEV_VPS) down

dev-vps-ps:
	$(COMPOSE_DEV_VPS) ps

# Deploy only on DEV VPS: validates Git and schema requirement before pulling.
# SCHEMA=1 is the operator's explicit authorization for DB schema synchronization.
dev-vps-update:
	@SCHEMA="$(SCHEMA)" ALLOW_DESTRUCTIVE_SCHEMA="$(ALLOW_DESTRUCTIVE_SCHEMA)" bash scripts/dev-vps-update.sh

# Do not bypass safety gates with an obsolete image-rebuild workflow.
dev-vps-update-build:
	@echo "ERROR: deprecated on volume-mounted DEV. Use dev-vps-update (no build)."; exit 2

# BEGREEN — consulta READ-ONLY de cambios manuales en catálogo DEV VPS.
# Ejecutar SOLO dentro de /home/fede/www/begreen en el VPS; nunca hace SSH.
.PHONY: dev-vps-catalog-audit
dev-vps-catalog-audit:
	@test "$(CURDIR)" = "/home/fede/www/begreen" || { echo "CATALOG_CHANGE_GUARD=ERROR: solo DEV VPS"; exit 2; }
	@test -n "$(SINCE)" || { echo "CATALOG_CHANGE_GUARD=ERROR: SINCE obligatorio"; exit 2; }
	@$(COMPOSE_DEV_VPS) exec -T php php bin/console app:catalog:changes --since="$(SINCE)"

# BEGREEN #74 PLAYWRIGHT BEGIN — QA Calculadora desde equipo local contra DEV
.PHONY: qa-smoke qa-calculator qa-calculator-transport qa-calculator-materials qa-persistence qa-failed qa-report
qa-smoke:
	@bash qa-playwright/scripts/run.sh smoke
qa-calculator:
	@bash qa-playwright/scripts/run.sh calculator
qa-calculator-transport:
	@bash qa-playwright/scripts/run.sh transport
qa-calculator-materials:
	@bash qa-playwright/scripts/run.sh materials
qa-persistence:
	@bash qa-playwright/scripts/run.sh p2
qa-failed:
	@bash qa-playwright/scripts/run.sh failed
qa-report:
	@cd qa-playwright && npm run qa:report
# BEGREEN #74 PLAYWRIGHT END

# BEGREEN #82 QA VERIFIER BEGIN
.PHONY: qa-verifier
qa-verifier:
	@bash qa-playwright/scripts/run-verifier.sh
# BEGREEN #82 QA VERIFIER END
