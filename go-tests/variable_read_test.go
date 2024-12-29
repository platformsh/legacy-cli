package tests

import (
	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"testing"
)

func TestVariableList(t *testing.T) {
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
	main.SetCurrentDeployment(&mockapi.Deployment{
		WebApps: map[string]mockapi.App{
			"app": {Name: "app", Type: "golang:1.23", Size: "M", Disk: 2048, Mounts: map[string]mockapi.Mount{}},
		},
		Routes: make(map[string]any),
		Links:  mockapi.MakeHALLinks("self=/projects/" + projectID + "/environments/main/deployment/current"),
	})
	envs := []*mockapi.Environment{main}
	apiHandler.SetEnvironments(envs)

	apiHandler.SetProjectVariables(projectID, []*mockapi.Variable{
		{
			Name:         "bar",
			IsSensitive:  true,
			VisibleBuild: true,
		},
	})

	apiHandler.SetEnvLevelVariables(projectID, "main", []*mockapi.EnvLevelVariable{
		{
			Variable: mockapi.Variable{
				Name:           "env:FOO",
				Value:          "bar",
				VisibleRuntime: true,
			},
			IsEnabled:     true,
			Inherited:     false,
			IsInheritable: false,
		},
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	f.Run("cc")
	assertTrimmed(t, `
+---------+-------------+---------------------------+---------+
| Name    | Level       | Value                     | Enabled |
+---------+-------------+---------------------------+---------+
| bar     | project     | [Hidden: sensitive value] |         |
| env:FOO | environment | bar                       | true    |
+---------+-------------+---------------------------+---------+
`, f.Run("var", "-p", projectID, "-e", "."))

	assertTrimmed(t, "false", f.Run("var:get", "-p", projectID, "-e", ".", "env:FOO", "-P", "is_sensitive"))
	assertTrimmed(t, "true", f.Run("var:get", "-p", projectID, "-e", ".", "env:FOO", "-P", "visible_runtime"))

	assertTrimmed(t, "false", f.Run("var:get", "-p", projectID, "-l", "p", "bar", "-P", "visible_runtime"))
	_, stdErr, err := f.RunCombinedOutput("var:get", "-p", projectID, "-e", ".", "bar", "-P", "value")
	assert.Error(t, err)
	assert.Contains(t, stdErr, "The variable is sensitive")
}
