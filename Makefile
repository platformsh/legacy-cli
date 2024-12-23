GO_TESTS_DIR=go-tests

.PHONY: composer-dev
composer-dev:
	composer install --no-interaction

.PHONY: clean
clean:
	rm config/cache/container.php

.PHONY: lint-phpstan
lint-phpstan: composer-dev
	./vendor/bin/phpstan analyse

.PHONY: lint-php-cs-fixer
lint-php-cs-fixer: composer-dev
	./vendor/bin/php-cs-fixer check --config .php-cs-fixer.dist.php --diff

.PHONY: lint-gofmt
lint-gofmt:
	cd $(GO_TESTS_DIR) && go fmt ./...

.PHONY: lint-golangci
lint-golangci:
	command -v golangci-lint >/dev/null || go install github.com/golangci/golangci-lint/cmd/golangci-lint@$(GOLANGCI_LINT_VERSION)
	cd $(GO_TESTS_DIR) && golangci-lint run

.PHONY: lint
lint: lint-gofmt lint-golangci lint-php-cs-fixer lint-phpstan

.PHONY: test
test:
	./vendor/bin/phpunit --exclude-group slow
	cd $(GO_TESTS_DIR) && go test -v -count=1 ./...
