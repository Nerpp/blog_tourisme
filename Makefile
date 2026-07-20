COMPOSE ?= docker compose
SURVEY_AFTER_MERGE ?= 1

.PHONY: setup build up composer-install node-install node-build node-dev \
	test-db-reset test test-all quality e2e quality-e2e coverage \
	work-start work-to-dev prod-secrets-sync prod-redeploy survey

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

test-db-reset:
	COMPOSE="$(COMPOSE)" scripts/reset-test-database.sh

test:
	$(MAKE) test-db-reset
	$(COMPOSE) exec -e SKIP_TEST_DB_RESET=1 php composer test

quality:
	$(MAKE) test-db-reset
	$(COMPOSE) exec -e SKIP_TEST_DB_RESET=1 php composer quality

e2e:
	$(MAKE) test-db-reset
	$(COMPOSE) exec -e SKIP_TEST_DB_RESET=1 php composer test:e2e

quality-e2e:
	$(MAKE) test-db-reset
	$(COMPOSE) exec -e SKIP_TEST_DB_RESET=1 php composer quality
	$(MAKE) test-db-reset
	$(COMPOSE) exec -e SKIP_TEST_DB_RESET=1 php composer test:e2e

coverage:
	$(MAKE) test-db-reset
	$(COMPOSE) exec -e SKIP_TEST_DB_RESET=1 -e XDEBUG_MODE=coverage php composer test:coverage

test-all:
	$(COMPOSE) exec -T php composer validate --strict
	$(COMPOSE) exec -T php composer audit
	$(COMPOSE) exec -T php php bin/console lint:container
	$(COMPOSE) exec -T php php bin/console lint:twig templates
	$(COMPOSE) run --rm --user root node chown -R $${DOCKER_UID:-1000}:$${DOCKER_GID:-1000} /var/www/html/node_modules
	$(COMPOSE) run --rm node
	$(COMPOSE) exec -T php php vendor/bin/phpstan analyse
	$(MAKE) test-db-reset
	$(COMPOSE) exec -T php php bin/console doctrine:migrations:status --env=test
	$(COMPOSE) exec -T php php bin/console doctrine:schema:validate --env=test
	$(COMPOSE) exec -T -e SKIP_TEST_DB_RESET=1 -e XDEBUG_MODE=coverage php composer test:coverage
	$(MAKE) test-db-reset
	$(COMPOSE) exec -T -e SKIP_TEST_DB_RESET=1 php composer test:e2e
	
work-start:
	@COMPOSE="$(COMPOSE)" scripts/work-start.sh

work-to-dev:
	@COMPOSE="$(COMPOSE)" \
		PR_TITLE="$(PR_TITLE)" \
		SURVEY_AFTER_MERGE="$(SURVEY_AFTER_MERGE)" \
		SURVEY_TIMEOUT="$(SURVEY_TIMEOUT)" \
		SURVEY_INTERVAL="$(SURVEY_INTERVAL)" \
		scripts/work-to-dev.sh

prod-secrets-sync:
	@scripts/prod-secrets-sync.sh

prod-redeploy:
	@scripts/prod-redeploy.sh

survey:
	@scripts/survey.sh
