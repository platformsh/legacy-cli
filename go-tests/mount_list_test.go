package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/stretchr/testify/assert"
)

func TestMountList(t *testing.T) {
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
	main.Links["pf:ssh:app"] = mockapi.HALLink{HREF: "ssh://" + projectID + "--app@ssh.example.com"}
	main.SetCurrentDeployment(&mockapi.Deployment{
		WebApps: map[string]mockapi.App{
			"app": {
				Name: "app",
				Type: "golang:1.23",
				Size: "AUTO",
				Mounts: map[string]mockapi.Mount{
					"/public/sites/default/files": {Source: "local", SourcePath: "files"},
				}},
		},
		Services: map[string]mockapi.App{},
		Routes:   map[string]any{},
		Workers:  map[string]mockapi.Worker{},
		Links:    mockapi.MakeHALLinks("self=/projects/" + projectID + "/environments/main/deployment/current"),
	})

	apiHandler.SetEnvironments([]*mockapi.Environment{main})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assertTrimmed(t, `
Mount path	Definition
public/sites/default/files	"source: local
source_path: files"
`, f.Run("mounts", "-p", projectID, "-e", "main", "--refresh", "--format", "tsv"))

	assert.Equal(t, "public/sites/default/files\n", f.Run("mounts", "-p", projectID, "-e", "main", "--paths"))
}

func TestMountListLocal(t *testing.T) {
	f := cmdWithLocalApp(t, &mockapi.App{
		Name: "local-app",
		Type: "golang:1.24",
		Size: "L",
		Mounts: map[string]mockapi.Mount{
			"/tmp": {Source: "local", SourcePath: "tmp"},
		},
	})

	assertTrimmed(t, `
+------------+------------------+
| Mount path | Definition       |
+------------+------------------+
| tmp        | source: local    |
|            | source_path: tmp |
+------------+------------------+
`, f.Run("mounts"))
}
