PHP_VERSIONS := 7.4 8.0 8.1 8.2 8.3 8.4 8.5
PHP_VERSION  ?= 8.3

# Passed to the container so files written into the bind mount stay host-owned.
export DOCKER_UID := $(shell id -u)
export DOCKER_GID := $(shell id -g)

.PHONY: test test-all stan cs cs-check rector rector-check check hooks fixtures spec \
        playground playground-data playground-check mutation \
        release-check release-notes \
        certify integration clusters clusters-down clean

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

## Mutation testing: does the suite actually notice when a rule stops firing?
## Slow on purpose — it runs the suite once per surviving mutant.
mutation:
	docker compose run --rm --build mutation

stan:
	PHP_VERSION=8.3 docker compose run --rm -T php sh -c "composer update --no-progress && vendor/bin/phpstan analyse"

## Apply the coding standard.
cs:
	vendor/bin/php-cs-fixer fix

## Report on it without touching anything — what CI runs.
cs-check:
	vendor/bin/php-cs-fixer fix --dry-run --diff

## Apply the Rector rules (native property types, dead code, early returns).
rector:
	vendor/bin/rector process

rector-check:
	vendor/bin/rector process --dry-run

## Everything the pre-push hook runs, on the dev PHP.
check: cs-check rector-check
	vendor/bin/phpstan analyse --no-progress
	vendor/bin/phpunit

## Install the versioned git hooks. One command, no copying: it points git at
## tools/hooks, so the hooks stay under version control.
hooks:
	git config core.hooksPath tools/hooks
	@echo "pre-push hook active. Bypass a single push with --no-verify."

## Regenerate the golden fixture files (review the diff before committing!)
fixtures:
	UPDATE_FIXTURES=1 vendor/bin/phpunit --testsuite=os-query-digest

## Rebuild the two files the browser playground ships: the library as one file,
## and every fixture already digested. Review the diff before committing —
## PlaygroundTest fails until the committed data matches src/.
playground-data:
	php tools/build-playground.php

## Serve the playground on :8080. Static files only; the PHP that runs the
## library is the one compiled to wasm, fetched by the page.
playground: playground-data
	@echo "→ http://localhost:8080"
	@php -S localhost:8080 -t playground

## Drive the playground in a real browser and assert what it renders.
##   npm install -g playwright && npx playwright install chromium
## NODE_PATH is how node finds a globally installed playwright. CI never runs
## this: the guard that has to be automatic is PlaygroundTest, which needs
## neither node nor a browser.
playground-check:
	NODE_PATH="$${NODE_PATH:-$$(npm root -g)}" node tools/playground-browser-check.mjs

## Refresh the OpenSearch spec snapshot, then let the tests flag what changed.
## SPEC_REF pins a branch, tag or commit: make spec SPEC_REF=e027edc
spec:
	php tools/refresh-spec.php
	@echo
	@vendor/bin/phpunit --filter SpecCoverageTest || \
		echo ">> OpenSearch changed its type list. Classify the new types in resources/coverage.json."

## Start throwaway OpenSearch nodes: 2.x on :9202, 3.x on :9203.
## --wait blocks on the healthchecks, so the next target never races the boot.
clusters:
	docker compose --profile certify up -d --wait os2 os3

clusters-down:
	docker compose --profile certify down -v

## Re-certify which OpenSearch versions accept the queries we render, and
## rewrite resources/versions.json. Review the diff before committing.
## Pin versions: make certify OS2_VERSION=2.11.1 OS3_VERSION=3.0.0
certify: clusters
	php tools/certify.php
	@vendor/bin/phpunit --filter CertificationTest
	@$(MAKE) clusters-down

## Replay the committed matrix against live nodes. This is the regression
## guard: it fails if a version stopped behaving the way the file says.
integration: clusters
	OPENSEARCH_URL=http://localhost:9202 vendor/bin/phpunit --testsuite=integration
	OPENSEARCH_URL=http://localhost:9203 vendor/bin/phpunit --testsuite=integration
	@$(MAKE) clusters-down

## May this version be tagged? Checks that CHANGELOG.md has an entry for it and
## that the entry's Fingerprints: line agrees with the hashes pinned in
## tests/fixtures. Run it before every tag: make release-check VERSION=v0.7.0
release-check:
	@test -n "$(VERSION)" || { echo "usage: make release-check VERSION=v0.7.0"; exit 2; }
	@php tools/changelog.php check $(VERSION)

## The notes for one release, straight out of CHANGELOG.md — this is what the
## GitHub release ships, so the notes are the ones reviewed in the pull request:
##   make release-notes VERSION=v0.7.0 | gh release create v0.7.0 --verify-tag --latest --notes-file -
release-notes:
	@test -n "$(VERSION)" || { echo "usage: make release-notes VERSION=v0.7.0"; exit 2; }
	@php tools/changelog.php section $(VERSION)

clean:
	docker compose --profile certify down -v --remove-orphans
	rm -rf vendor .vendor .phpunit.result.cache .phpunit.cache
