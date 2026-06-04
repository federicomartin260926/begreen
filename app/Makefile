COMPOSE_DEV := docker compose -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_PROD := docker compose -f docker-compose.yml -f docker-compose.prod.yml

.PHONY: up up-prod prod-up down restart logs php console composer-install assets-install assets-build cache-clear schema-update fixtures test doctor prepare-private-storage prepare-private-storage-prod prod-restart prod-logs prod-console prod-cache-clear prod-schema-update

up:
	$(COMPOSE_DEV) up -d --build
	$(MAKE) prepare-private-storage

up-prod:
	$(COMPOSE_PROD) up -d --build
	$(MAKE) prepare-private-storage-prod

prod-up: up-prod

down:
	$(COMPOSE_DEV) down

restart:
	$(COMPOSE_DEV) restart

logs:
	$(COMPOSE_DEV) logs -f --tail=100

php:
	$(COMPOSE_DEV) exec php sh

console:
	$(COMPOSE_DEV) exec php php bin/console $(ARGS)

composer-install:
	$(COMPOSE_DEV) exec php composer install --no-interaction

assets-install:
	$(COMPOSE_DEV) exec php npm ci --no-audit --no-fund

assets-build:
	$(COMPOSE_DEV) exec php npm run build

cache-clear:
	$(COMPOSE_DEV) exec php php bin/console cache:clear

schema-update:
	$(COMPOSE_DEV) exec php php bin/console doctrine:schema:update --dump-sql

fixtures:
	$(COMPOSE_DEV) exec php php bin/console doctrine:fixtures:load --no-interaction

test:
	$(COMPOSE_DEV) exec php ./vendor/bin/phpunit

doctor:
	$(COMPOSE_DEV) ps
	$(COMPOSE_DEV) exec php php -v
	$(COMPOSE_DEV) exec php php -m
	$(COMPOSE_DEV) exec php php bin/console about
	$(COMPOSE_DEV) exec php php bin/console doctrine:schema:update --dump-sql
	$(COMPOSE_DEV) exec php ./vendor/bin/phpunit --list-tests

prepare-private-storage:
	$(COMPOSE_DEV) exec -u root php sh -lc 'mkdir -p /app/var/private/stripe-invoices && chown -R www-data:www-data /app/var/private && chmod -R u+rwX,g+rwX /app/var/private'

prepare-private-storage-prod:
	$(COMPOSE_PROD) exec -u root php sh -lc 'mkdir -p /app/var/private/stripe-invoices && chown -R www-data:www-data /app/var/private && chmod -R u+rwX,g+rwX /app/var/private'

prod-restart:
	$(COMPOSE_PROD) restart

prod-logs:
	$(COMPOSE_PROD) logs -f --tail=100

prod-console:
	$(COMPOSE_PROD) exec php php bin/console $(ARGS)

prod-cache-clear:
	$(COMPOSE_PROD) exec php php bin/console cache:clear

prod-schema-update:
	$(COMPOSE_PROD) exec php php bin/console doctrine:schema:update --dump-sql
