GO_TESTS_DIR=go-tests

tools=vendor/bin/phpstan vendor/bin/php-cs-fixer

$(tools):
	composer install

.PHONY: clean
clean:
	rm config/cache/container.php

.PHONY: lint-phpstan
lint-phpstan: vendor/bin/phpstan
	./vendor/bin/phpstan analyse

.PHONY: lint-php-cs-fixer
lint-php-cs-fixer: vendor/bin/php-cs-fixer
	./vendor/bin/php-cs-fixer check src

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
