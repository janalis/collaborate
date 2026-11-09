# janalis/collaborate: run `make help` for the targets.
#
# The database tests read COLLABORATE_DATABASE_URL (a MariaDB or MySQL 8 server where the test user may create
# `collaborate_*` databases). Without it in the environment, the value of a local phpunit.xml is used.

SHELL := bash
.DEFAULT_GOAL := help

PHP ?= php
COMPOSER ?= composer
NPM ?= npm
FRANKENPHP ?= frankenphp
PHPUNIT_ARGS ?=
ARGS ?=

COLLABORATE_DATABASE_URL ?= $(shell sed -n 's/.*name="COLLABORATE_DATABASE_URL" value="\([^"]*\)".*/\1/p' phpunit.xml 2>/dev/null | sed 's/&amp;/\&/g')
export COLLABORATE_DATABASE_URL
export FRANKENPHP

CONSOLE := $(PHP) tests/Application/bin/console.php

# Linters of the docs and workflows; the release workflow empties them (`make verify MDLINT= EDITORCONFIG= ACTIONLINT=`).
MDLINT ?= docs/node_modules/.bin/markdownlint-cli2
EDITORCONFIG ?= go run github.com/editorconfig-checker/editorconfig-checker/v3/cmd/editorconfig-checker@v3.4.0
ACTIONLINT ?= go run github.com/rhysd/actionlint/cmd/actionlint@v1.7.12
BUMP ?= patch

.PHONY: help install fmt fmt-check lint analyze validate test test-unit test-functional test-integration test-js lint-js coverage infection app-setup e2e screenshots verify clean
.PHONY: docs docs-dev lint-md fmt-md lint-editorconfig lint-actions test-tools release-notes

help: ## List the targets
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN { FS = ":.*## " } { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 }'

install: ## Install the PHP and root JavaScript dependencies and build the test applications' assets
	$(COMPOSER) install --no-interaction
	$(NPM) ci
	node tests/Application/bin/setup-assets.mjs

fmt: ## Format the PHP code (Mago) and the JavaScript (Biome)
	vendor/bin/mago fmt
	$(NPM) run lint:fix

fmt-check: ## Check the PHP formatting
	vendor/bin/mago fmt --check

lint: lint-md lint-editorconfig lint-actions ## Lint the PHP code (Mago), the Markdown, .editorconfig and the workflows
	vendor/bin/mago lint

analyze: ## Analyze the PHP code statically (Mago)
	vendor/bin/mago analyze

validate: ## Validate composer.json strictly
	$(COMPOSER) validate --strict

test: ## Run every PHPUnit suite: unit, functional and integration (PHPUNIT_ARGS="--filter …")
	vendor/bin/phpunit $(PHPUNIT_ARGS)

test-unit: ## Run the unit suite
	vendor/bin/phpunit --testsuite unit $(PHPUNIT_ARGS)

test-functional: ## Run the MariaDB functional suite
	vendor/bin/phpunit --testsuite functional $(PHPUNIT_ARGS)

test-integration: ## Run the bundle inside the two test applications (with and without EasyAdmin)
	vendor/bin/phpunit --testsuite integration $(PHPUNIT_ARGS)

test-js: ## Run the Vitest suite
	$(NPM) test

lint-js: ## Lint the JavaScript (Biome) and type-check the modules that opt in (TypeScript)
	$(NPM) run lint

coverage: ## Require 100% coverage: PHPUnit (pcov, src/ and tools/, every suite) and Vitest (thresholds)
	$(PHP) -d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit --coverage-text --coverage-html var/coverage --coverage-clover var/coverage/clover.xml $(PHPUNIT_ARGS)
	$(PHP) tests/bin/check-coverage.php var/coverage/clover.xml
	$(NPM) run test:coverage

infection: ## Mutation-test src/ against every PHPUnit suite (reports in var/infection/)
	$(PHP) -d pcov.enabled=1 vendor/bin/infection --show-mutations=max $(ARGS)

app-setup: ## Prepare the test applications for the browser: assets, EasyAdmin's public files, e2e databases
	node tests/Application/bin/setup-assets.mjs
	rm -rf tests/Application/var/plain/cache tests/Application/var/easyadmin/cache tests/Application/var/host/cache tests/Application/var/host/log/revoked-folders.json
	COLLABORATE_APP=easyadmin APP_ENV=e2e $(CONSOLE) assets:install tests/Application/EasyAdmin/public --no-interaction
	COLLABORATE_APP=plain APP_ENV=e2e $(CONSOLE) app:setup
	COLLABORATE_APP=easyadmin APP_ENV=e2e $(CONSOLE) app:setup
	COLLABORATE_APP=plain APP_ENV=e2e_limits $(CONSOLE) app:setup
	for env in e2e_div e2e_table e2e_tailwind; do COLLABORATE_APP=plain APP_ENV=$$env $(CONSOLE) app:setup || exit 1; done
	COLLABORATE_APP=host APP_ENV=e2e $(CONSOLE) app:setup
	mkdir -p tests/Application/var/plain/run && chmod 0750 tests/Application/var/plain/run
	COLLABORATE_APP=plain APP_ENV=e2e_ws COLLABORATE_HUB_RUN=$(CURDIR)/tests/Application/var/plain/run $(CONSOLE) app:setup

e2e: app-setup ## Run the Playwright scenarios against the three applications under FrankenPHP and the WebSocket hub (ARGS="--project=plain …")
	npx playwright test $(ARGS)

screenshots: app-setup ## Retake the screenshots of the docs and the README (docs/public/screenshots/) that changed
	npx playwright test -c playwright.screenshots.config.js --update-snapshots=changed $(ARGS)

verify: validate fmt-check lint analyze test lint-js test-js ## Everything CI checks, but the browser and mutation runs

docs/node_modules: docs/package-lock.json
	$(NPM) ci --prefix docs
	@touch $@

docs: docs/node_modules ## Build the documentation site into docs/.vitepress/dist
	$(NPM) --prefix docs run build

docs-dev: docs/node_modules ## Serve the documentation site on http://localhost:5173/collaborate/
	$(NPM) --prefix docs run dev

lint-md: $(if $(MDLINT),docs/node_modules) ## Lint the Markdown files (markdownlint)
	$(if $(MDLINT),$(MDLINT),@echo "markdownlint skipped")

fmt-md: docs/node_modules ## Apply markdownlint's automatic fixes
	$(MDLINT) --fix

lint-editorconfig: ## Check every text file against .editorconfig (editorconfig-checker)
	$(if $(EDITORCONFIG),$(EDITORCONFIG),@echo "editorconfig-checker skipped")

lint-actions: ## Lint the GitHub workflows (actionlint)
	$(if $(ACTIONLINT),$(ACTIONLINT),@echo "actionlint skipped")

test-tools: ## Run the release tool's tests
	vendor/bin/phpunit -c phpunit.xml.dist --testsuite tools $(PHPUNIT_ARGS)

release-notes: ## Print the notes the next release would publish (BUMP=patch|minor|major|X.Y.Z); CHANGELOG.md is untouched
	@tmp=$$(mktemp -d) && trap 'rm -rf "$$tmp"' EXIT && cp CHANGELOG.md "$$tmp/CHANGELOG.md" \
		&& $(PHP) tools/relprep.php --bump "$(BUMP)" --changelog "$$tmp/CHANGELOG.md" --notes "$$tmp/notes.md" >/dev/null \
		&& cat "$$tmp/notes.md"

clean: ## Remove the caches, reports and generated test application files
	rm -rf var .phpunit.cache tests/Application/var tests/Application/*/public/bundles test-results playwright-report
