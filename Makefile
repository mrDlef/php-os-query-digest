PHP_VERSIONS := 7.4 8.0 8.1 8.2 8.3 8.4 8.5
PHP_VERSION  ?= 8.3

# Passed to the container so files written into the bind mount stay host-owned.
export DOCKER_UID := $(shell id -u)
export DOCKER_GID := $(shell id -g)

.PHONY: test test-all stan fixtures spec clean

## Run the test suite in Docker for one PHP version: make test PHP_VERSION=7.4
test:
	PHP_VERSION=$(PHP_VERSION) docker compose run --rm --build php

## Run the test suite across every supported PHP version.
## -T and </dev/null are both required: otherwise `docker compose run` swallows
## the remaining stdin and the loop silently stops after the first version.
test-all:
	@for v in $(PHP_VERSIONS); do \
		echo "=====  PHP $$v  ====="; \
		PHP_VERSION=$$v docker compose run --rm -T --build php </dev/null || exit 1; \
	done

stan:
	PHP_VERSION=8.3 docker compose run --rm -T php sh -c "composer update --no-progress && vendor/bin/phpstan analyse"

## Regenerate the golden fixture files (review the diff before committing!)
fixtures:
	UPDATE_FIXTURES=1 vendor/bin/phpunit --testsuite=os-query-digest

## Refresh the OpenSearch spec snapshot, then let the tests flag what changed.
## SPEC_REF pins a branch, tag or commit: make spec SPEC_REF=e027edc
spec:
	php tools/refresh-spec.php
	@echo
	@vendor/bin/phpunit --filter SpecCoverageTest || \
		echo ">> OpenSearch changed its type list. Classify the new types in resources/coverage.json."

clean:
	docker compose down -v --remove-orphans
	rm -rf vendor .vendor .phpunit.result.cache .phpunit.cache
