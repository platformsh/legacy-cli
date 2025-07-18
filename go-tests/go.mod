module github.com/platformsh/legacy-cli/tests

go 1.24

# todo upgrade cli
replace github.com/platformsh/cli => /Users/efa/projects/cli

require (
	github.com/go-chi/chi/v5 v5.2.2
	github.com/platformsh/cli v0.0.0-20250512110214-68e4962f0990
	github.com/stretchr/testify v1.10.0
	golang.org/x/crypto v0.38.0
)

require (
	github.com/davecgh/go-spew v1.1.2-0.20180830191138-d8f796af33cc // indirect
	github.com/oklog/ulid/v2 v2.1.0 // indirect
	github.com/pmezard/go-difflib v1.0.1-0.20181226105442-5d4384ee4fb2 // indirect
	golang.org/x/sys v0.33.0 // indirect
	gopkg.in/yaml.v3 v3.0.1 // indirect
)
