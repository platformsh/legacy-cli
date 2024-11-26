package tests

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestAppList(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	projectID := "nu8ohgeizah1a"

	apiHandler.SetProjects([]*mockapi.Project{{
		ID: projectID,
		Links: mockapi.MakeHALLinks("self=/projects/"+projectID,
			"environments=/projects/"+projectID+"/environments"),
		DefaultBranch: "main",
	}})

	main := makeEnv(projectID, "main", "production", "active", nil)
	main.SetCurrentDeployment(&mockapi.Deployment{
		WebApps: map[string]mockapi.App{
			"app": {Name: "app", Type: "golang:1.23", Size: "AUTO"},
		},
		Services: map[string]mockapi.App{},
		Routes:   map[string]any{},
		Workers: map[string]mockapi.Worker{
			"app--worker1": {
				App:    mockapi.App{Name: "app--worker1", Type: "golang:1.23", Size: "AUTO"},
				Worker: mockapi.WorkerInfo{Commands: mockapi.Commands{Start: "sleep 60"}},
			},
		},
		Links: mockapi.MakeHALLinks("self=/projects/" + projectID + "/environments/main/deployment/current"),
	})

	envs := []*mockapi.Environment{
		main,
		makeEnv(projectID, "staging", "staging", "active", "main"),
		makeEnv(projectID, "dev", "development", "active", "staging"),
		makeEnv(projectID, "fix", "development", "inactive", "dev"),
	}

	apiHandler.SetEnvironments(envs)

	run := runnerWithAuth(t, apiServer.URL, authServer.URL)

	assert.Equal(t, strings.TrimLeft(`
Name	Type
app	golang:1.23
`, "\n"), run("apps", "-p", projectID, "-e", ".", "--refresh", "--format", "tsv"))

	assert.Equal(t, strings.TrimLeft(`
+--------------+-------------+-------------------+
| Name         | Type        | Commands          |
+--------------+-------------+-------------------+
| app--worker1 | golang:1.23 | start: 'sleep 60' |
+--------------+-------------+-------------------+
`, "\n"), run("workers", "-v", "-p", projectID, "-e", "."))

	runCombinedOutput := runnerCombinedOutput(t, apiServer.URL, authServer.URL)
	co, err := runCombinedOutput("services", "-p", projectID, "-e", "main")
	require.NoError(t, err)
	assert.Contains(t, co, "No services found")
}
