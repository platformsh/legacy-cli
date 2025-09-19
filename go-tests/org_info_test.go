package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestOrgInfo(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "user-for-org-info-test"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg("org-id-1", "org-1", "Org 1", myUserID, "flexible"),
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assert.Contains(t, f.Run("org:info", "-o", "org-1", "--format", "csv", "--refresh"), `Property,Value
id,org-id-1
type,flexible
name,org-1
label,Org 1
owner_id,user-for-org-info-test
capabilities,`)

	assert.Equal(t, "Org 1\n", f.Run("org:info", "-o", "org-1", "label"))

	_, stdErr, err := f.RunCombinedOutput("org:info", "-o", "org-1", "label", "New Label")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Property label set to: New Label\n")

	assert.Equal(t, "New Label\n", f.Run("org:info", "-o", "org-1", "label"))
}
