package tests

import (
	"net/http/httptest"
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
		makeOrg("org-id-1", "acme", "ACME Inc.", myUserID, "flexible"),
	})

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	// TODO disable the cache?
	f.Run("cc")

	assertTrimmed(t, `
+------+-----------+----------+--------------------------------------+
| Name | Label     | Type     | Owner email                          |
+------+-----------+----------+--------------------------------------+
| acme | ACME Inc. | flexible | user-for-org-create-test@example.com |
+------+-----------+----------+--------------------------------------+
`, f.Run("orgs"))

	_, stdErr, err := f.RunCombinedOutput("org:create", "--name", "hooli", "--yes")
	assert.Error(t, err)
	assert.Contains(t, stdErr, "--country is required")

	_, stdErr, err = f.RunCombinedOutput("org:create", "--name", "hooli", "--yes", "--country", "XY")
	assert.Error(t, err)
	assert.Contains(t, stdErr, "Invalid country: XY")

	_, stdErr, err = f.RunCombinedOutput("org:create", "--name", "hooli", "--yes", "--country", "US")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Hooli")

	assertTrimmed(t, `
+-------+-----------+----------+--------------------------------------+
| Name  | Label     | Type     | Owner email                          |
+-------+-----------+----------+--------------------------------------+
| acme  | ACME Inc. | flexible | user-for-org-create-test@example.com |
| hooli | Hooli     | flexible | user-for-org-create-test@example.com |
+-------+-----------+----------+--------------------------------------+
`, f.Run("orgs"))
}
