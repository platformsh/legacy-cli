package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/stretchr/testify/assert"
)

func TestVariableCreate(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	projectID := mockapi.ProjectID()

	apiHandler.SetProjects([]*mockapi.Project{{
		ID: projectID,
		Links: mockapi.MakeHALLinks("self=/projects/"+projectID,
			"environments=/projects/"+projectID+"/environments"),
		DefaultBranch: "main",
	}})
	main := makeEnv(projectID, "main", "production", "active", nil)
	main.Links["#variables"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/variables"}
	main.Links["#manage-variables"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/variables"}
	envs := []*mockapi.Environment{main}
	apiHandler.SetEnvironments(envs)

	apiHandler.SetProjectVariables(projectID, []*mockapi.Variable{
		{
			Name:         "existing",
			IsSensitive:  true,
			VisibleBuild: true,
		},
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	_, stdErr, err := f.RunCombinedOutput("var:create", "-p", projectID, "-l", "e", "-e", "main", "env:TEST", "--value", "env-level-value")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Creating variable env:TEST on the environment main")

	assertTrimmed(t, "env-level-value", f.Run("var:get", "-p", projectID, "-e", "main", "env:TEST", "-P", "value"))

	_, stdErr, err = f.RunCombinedOutput("var:create", "-p", projectID, "env:TEST", "-l", "p", "--value", "project-level-value")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Creating variable env:TEST on the project "+projectID)

	assertTrimmed(t, "project-level-value", f.Run("var:get", "-p", projectID, "-e", "main", "env:TEST", "-P", "value", "-l", "p"))
	assertTrimmed(t, "env-level-value", f.Run("var:get", "-p", projectID, "-e", "main", "env:TEST", "-P", "value", "-l", "e"))

	_, stdErr, err = f.RunCombinedOutput("var:create", "-p", projectID, "existing", "-l", "p", "--value", "test")
	assert.Error(t, err)
	assert.Contains(t, stdErr, "The variable already exists")

	_, _, err = f.RunCombinedOutput("var:update", "-p", projectID, "env:TEST", "-l", "p", "--value", "project-level-value2")
	assert.NoError(t, err)
	assertTrimmed(t, "project-level-value2", f.Run("var:get", "-p", projectID, "env:TEST", "-l", "p", "-P", "value"))

	assertTrimmed(t, "true", f.Run("var:get", "-p", projectID, "env:TEST", "-l", "p", "-P", "visible_runtime"))
	_, _, err = f.RunCombinedOutput("var:update", "-p", projectID, "env:TEST", "-l", "p", "--visible-runtime", "false")
	assert.NoError(t, err)
	assertTrimmed(t, "false", f.Run("var:get", "-p", projectID, "env:TEST", "-l", "p", "-P", "visible_runtime"))
}
