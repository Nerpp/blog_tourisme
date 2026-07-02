COMPOSE ?= docker compose

.PHONY: setup build up composer-install node-install node-build node-dev test test-all quality e2e quality-e2e coverage

setup: build up composer-install node-install node-build

build:
	$(COMPOSE) build php

up:
	$(COMPOSE) up -d

composer-install:
	$(COMPOSE) exec php composer install

node-install:
	$(COMPOSE) run --rm node npm install

node-build:
	$(COMPOSE) run --rm node npm run build

node-dev:
	$(COMPOSE) run --rm --service-ports node npm run dev

test:
	$(COMPOSE) exec php composer test

quality:
	$(COMPOSE) exec php composer quality

e2e:
	$(COMPOSE) exec php composer test:e2e

quality-e2e:
	$(COMPOSE) exec php composer quality:e2e

coverage:
	$(COMPOSE) exec -e XDEBUG_MODE=coverage php composer test:coverage

test-all:
	$(COMPOSE) exec -T php composer validate --strict
	$(COMPOSE) exec -T php composer audit
	$(COMPOSE) exec -T php php bin/console lint:container
	$(COMPOSE) exec -T php php bin/console lint:twig templates
	$(COMPOSE) exec -T php php bin/console doctrine:migrations:status --env=test
	$(COMPOSE) exec -T php php bin/console doctrine:schema:validate --env=test
	$(COMPOSE) run --rm node npm run build
	$(COMPOSE) exec -T -e XDEBUG_MODE=coverage php composer test:coverage
	$(COMPOSE) exec -T php composer test:e2e
	$(COMPOSE) exec -T php php vendor/bin/phpstan analyse
