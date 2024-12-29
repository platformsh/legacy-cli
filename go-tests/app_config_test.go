package tests

import (
	"encoding/base64"
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestAppConfig(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)

	projectID := mockapi.ProjectID()

	apiHandler.SetProjects([]*mockapi.Project{{
		ID: projectID,
		Links: mockapi.MakeHALLinks("self=/projects/"+projectID,
			"environments=/projects/"+projectID+"/environments"),
		DefaultBranch: "main",
	}})

	main := makeEnv(projectID, "main", "production", "active", nil)
	main.SetCurrentDeployment(&mockapi.Deployment{
		WebApps: map[string]mockapi.App{
			"app": {Name: "app", Type: "golang:1.23", Size: "M", Disk: 2048, Mounts: map[string]mockapi.Mount{}},
		},
		Links: mockapi.MakeHALLinks("self=/projects/" + projectID + "/environments/main/deployment/current"),
	})
	envs := []*mockapi.Environment{main}

	apiHandler.SetEnvironments(envs)

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assertTrimmed(t, `
name: app
type: 'golang:1.23'
size: M
disk: 2048
mounts: {  }
`, f.Run("app:config", "-p", projectID, "-e", ".", "--refresh"))

	assert.Equal(t, "golang:1.23\n", f.Run("app:config", "-p", projectID, "-e", ".", "--refresh", "-P", "type"))
}

func TestAppConfigLocal(t *testing.T) {
	f := cmdWithLocalApp(t, &mockapi.App{
		Name: "local-app",
		Type: "golang:1.24",
		Size: "L",
		Disk: 1024,
		Mounts: map[string]mockapi.Mount{
			"example": {
				Source:     "local",
				SourcePath: "example",
			},
		},
	})

	assertTrimmed(t, `
name: local-app
type: 'golang:1.24'
size: L
disk: 1024
mounts:
    example:
        source: local
        source_path: example
`, f.Run("app:config"))

	assert.Equal(t, "local\n", f.Run("app:config", "--property", "mounts.example.source"))
}

func cmdWithLocalApp(t *testing.T, app *mockapi.App) *cmdFactory {
	j, err := json.Marshal(app)
	require.NoError(t, err)

	return &cmdFactory{
		t:        t,
		extraEnv: []string{"PLATFORM_APPLICATION=" + base64.StdEncoding.EncodeToString(j)},
	}
}
