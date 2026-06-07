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
	$(COMPOSE) exec php composer quality:e2e
	$(COMPOSE) exec -e XDEBUG_MODE=coverage php composer test:coverage
