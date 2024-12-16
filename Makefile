GO_TESTS_DIR=go-tests

vendor/bin/phpstan:
	composer install

.PHONY: lint-phpstan
lint-phpstan: vendor/bin/phpstan
	./vendor/bin/phpstan analyse

.PHONY: lint-gofmt
lint-gofmt:
	cd $(GO_TESTS_DIR) && go fmt ./...

.PHONY: lint-golangci
lint-golangci:
	command -v golangci-lint >/dev/null || go install github.com/golangci/golangci-lint/cmd/golangci-lint@$(GOLANGCI_LINT_VERSION)
	cd $(GO_TESTS_DIR) && golangci-lint run

.PHONY: lint
lint: lint-gofmt lint-golangci lint-phpstan

.PHONY: test
test:
	./vendor/bin/phpunit --exclude-group slow
	cd $(GO_TESTS_DIR) && go test -v ./...
