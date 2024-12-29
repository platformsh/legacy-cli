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

func TestRouteList(t *testing.T) {
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
		Routes: mockRoutes(),
		Links:  mockapi.MakeHALLinks("self=/projects/" + projectID + "/environments/main/deployment/current"),
	})
	envs := []*mockapi.Environment{main}

	apiHandler.SetEnvironments(envs)

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assertTrimmed(t, `
+-------------------+----------+---------------------------+
| Route             | Type     | To                        |
+-------------------+----------+---------------------------+
| http://{default}  | redirect | https://main.example.com/ |
| https://{default} | upstream | app:http                  |
+-------------------+----------+---------------------------+
`, f.Run("routes", "-p", projectID, "-e", ".", "--refresh"))

	assert.Equal(t, "upstream\n", f.Run("route:get", "-p", projectID, "-e", ".", "https://{default}", "-P", "type"))
}

func TestRouteListLocal(t *testing.T) {
	f := &cmdFactory{t: t}
	routes, err := json.Marshal(mockRoutes())
	require.NoError(t, err)
	f.extraEnv = []string{"PLATFORM_ROUTES=" + base64.StdEncoding.EncodeToString(routes)}

	assertTrimmed(t, `
+-------------------+----------+---------------------------+
| Route             | Type     | To                        |
+-------------------+----------+---------------------------+
| http://{default}  | redirect | https://main.example.com/ |
| https://{default} | upstream | app:http                  |
+-------------------+----------+---------------------------+
`, f.Run("route:list"))

	assert.Equal(t, "redirect\n", f.Run("route:get", "http://{default}", "--property", "type"))
	assert.Equal(t, "https://main.example.com/\n", f.Run("route:get", "http://{default}", "--property", "to"))
}

// TODO make a Route type
func mockRoutes() map[string]any {
	return map[string]any{
		"https://main.example.com/": map[string]any{
			"primary":        true,
			"id":             "app",
			"production_url": "https://main.example.com/",
			"attributes":     map[string]any{},
			"type":           "upstream",
			"original_url":   "https://{default}",
			"http_access": map[string]any{
				"is_enabled": true,
				"addresses":  []string{},
				"basic_auth": map[string]any{},
			},
			"restrict_robots": true,
			"cache": map[string]any{
				"enabled":     true,
				"default_ttl": 0,
				"cookies":     []string{"*"},
				"headers":     []string{"Accept", "Accept-Language"},
			},
			"ssi": map[string]any{
				"enabled": false,
			},
			"upstream": "app:http",
		},
		"http://main.example.com/": map[string]any{
			"primary":        true,
			"id":             "app",
			"production_url": "http://main.example.com/",
			"attributes":     map[string]any{},
			"type":           "redirect",
			"original_url":   "http://{default}",
			"http_access": map[string]any{
				"is_enabled": true,
				"addresses":  []string{},
				"basic_auth": map[string]any{},
			},
			"restrict_robots": true,
			"to":              "https://main.example.com/",
		},
	}
}
