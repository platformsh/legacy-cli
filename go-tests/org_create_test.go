package tests

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestOrgCreate(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "user-for-org-create-test"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg("org-id-1", "acme", "ACME Inc.", myUserID),
	})

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	run := runnerWithAuth(t, apiServer.URL, authServer.URL)

	// TODO disable the cache?
	run("cc")

	assert.Equal(t, strings.TrimLeft(`
+------+-----------+--------------------------------------+
| Name | Label     | Owner email                          |
+------+-----------+--------------------------------------+
| acme | ACME Inc. | user-for-org-create-test@example.com |
+------+-----------+--------------------------------------+
`, "\n"), run("orgs"))

	runCombinedOutput := runnerCombinedOutput(t, apiServer.URL, authServer.URL)

	co, err := runCombinedOutput("org:create", "--name", "hooli", "--yes")
	assert.Error(t, err)
	assert.Contains(t, co, "--country is required")

	co, err = runCombinedOutput("org:create", "--name", "hooli", "--yes", "--country", "XY")
	assert.Error(t, err)
	assert.Contains(t, co, "Invalid country: XY")

	co, err = runCombinedOutput("org:create", "--name", "hooli", "--yes", "--country", "US")
	assert.NoError(t, err)
	assert.Contains(t, co, "Hooli")

	assert.Equal(t, strings.TrimLeft(`
+-------+-----------+--------------------------------------+
| Name  | Label     | Owner email                          |
+-------+-----------+--------------------------------------+
| acme  | ACME Inc. | user-for-org-create-test@example.com |
| hooli | Hooli     | user-for-org-create-test@example.com |
+-------+-----------+--------------------------------------+
`, "\n"), run("orgs"))
}
