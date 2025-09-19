package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/stretchr/testify/assert"
)

func TestWebConsole(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	projectID := mockapi.ProjectID()
	orgID := "org-" + mockapi.NumericID()

	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg(orgID, "cli-tests", "CLI Test Org", "my-user-id", "flexible"),
	})

	apiHandler.SetProjects([]*mockapi.Project{{
		ID: projectID,
		Links: mockapi.MakeHALLinks("self=/projects/"+projectID,
			"environments=/projects/"+projectID+"/environments"),
		DefaultBranch: "main",
		Organization:  orgID,
	}})
	apiHandler.SetEnvironments([]*mockapi.Environment{
		makeEnv(projectID, "main", "production", "active", nil),
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assert.Equal(t, "https://console.cli-tests.example.com\n", f.Run("console", "--browser", "0"))

	assert.Equal(t, "https://console.cli-tests.example.com/cli-tests/"+projectID+"\n",
		f.Run("console", "--browser", "0", "-p", projectID))

	assert.Equal(t, "https://console.cli-tests.example.com/cli-tests/"+projectID+"/main\n",
		f.Run("console", "--browser", "0", "-p", projectID, "-e", "."))

	// The "console" command should be aliased to "web".
	assert.Equal(t, "https://console.cli-tests.example.com\n", f.Run("web", "--browser", "0"))
}
