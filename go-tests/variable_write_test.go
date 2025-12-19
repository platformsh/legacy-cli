package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/stretchr/testify/assert"
)

// variableTestSetup holds common test infrastructure for variable tests.
type variableTestSetup struct {
	authServer *httptest.Server
	apiServer  *httptest.Server
	apiHandler *mockapi.Handler
	projectID  string
	mainEnv    *mockapi.Environment
	factory    *cmdFactory
}

// setupVariableTest creates the common test infrastructure for variable tests.
func setupVariableTest(t *testing.T) *variableTestSetup {
	authServer := mockapi.NewAuthServer(t)
	t.Cleanup(authServer.Close)

	apiHandler := mockapi.NewHandler(t)
	apiServer := httptest.NewServer(apiHandler)
	t.Cleanup(apiServer.Close)

	projectID := mockapi.ProjectID()

	apiHandler.SetProjects([]*mockapi.Project{{
		ID: projectID,
		Links: mockapi.MakeHALLinks(
			"self=/projects/"+projectID,
			"environments=/projects/"+projectID+"/environments",
		),
		DefaultBranch: "main",
	}})

	mainEnv := makeEnv(projectID, "main", "production", "active", nil)
	mainEnv.Links["#variables"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/variables"}
	mainEnv.Links["#manage-variables"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/variables"}

	return &variableTestSetup{
		authServer: authServer,
		apiServer:  apiServer,
		apiHandler: apiHandler,
		projectID:  projectID,
		mainEnv:    mainEnv,
		factory:    newCommandFactory(t, apiServer.URL, authServer.URL),
	}
}

func TestVariableCreate(t *testing.T) {
	s := setupVariableTest(t)
	s.apiHandler.SetEnvironments([]*mockapi.Environment{s.mainEnv})
	s.apiHandler.SetProjectVariables(s.projectID, []*mockapi.Variable{
		{
			Name:         "existing",
			IsSensitive:  true,
			VisibleBuild: true,
		},
	})

	f, p := s.factory, s.projectID

	_, stdErr, err := f.RunCombinedOutput("var:create", "-p", p, "-l", "e", "-e", "main", "env:TEST", "--value", "env-level-value")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Creating variable env:TEST on the environment main")

	assertTrimmed(t, "env-level-value", f.Run("var:get", "-p", p, "-e", "main", "env:TEST", "-P", "value"))

	_, stdErr, err = f.RunCombinedOutput("var:create", "-p", p, "env:TEST", "-l", "p", "--value", "project-level-value")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Creating variable env:TEST on the project "+p)

	assertTrimmed(t, "project-level-value", f.Run("var:get", "-p", p, "-e", "main", "env:TEST", "-P", "value", "-l", "p"))
	assertTrimmed(t, "env-level-value", f.Run("var:get", "-p", p, "-e", "main", "env:TEST", "-P", "value", "-l", "e"))

	_, stdErr, err = f.RunCombinedOutput("var:create", "-p", p, "existing", "-l", "p", "--value", "test")
	assert.Error(t, err)
	assert.Contains(t, stdErr, "The variable already exists")

	_, _, err = f.RunCombinedOutput("var:update", "-p", p, "env:TEST", "-l", "p", "--value", "project-level-value2")
	assert.NoError(t, err)
	assertTrimmed(t, "project-level-value2", f.Run("var:get", "-p", p, "env:TEST", "-l", "p", "-P", "value"))

	assertTrimmed(t, "true", f.Run("var:get", "-p", p, "env:TEST", "-l", "p", "-P", "visible_runtime"))
	_, _, err = f.RunCombinedOutput("var:update", "-p", p, "env:TEST", "-l", "p", "--visible-runtime", "false")
	assert.NoError(t, err)
	assertTrimmed(t, "false", f.Run("var:get", "-p", p, "env:TEST", "-l", "p", "-P", "visible_runtime"))
}

func TestVariableCreateWithAppScope(t *testing.T) {
	s := setupVariableTest(t)

	// Set up deployment with app names for validation.
	s.mainEnv.SetCurrentDeployment(&mockapi.Deployment{
		WebApps: map[string]mockapi.App{
			"app1": {Name: "app1", Type: "golang:1.23"},
			"app2": {Name: "app2", Type: "php:8.3"},
		},
		Routes: make(map[string]any),
		Links:  mockapi.MakeHALLinks("self=/projects/" + s.projectID + "/environments/main/deployment/current"),
	})
	s.apiHandler.SetEnvironments([]*mockapi.Environment{s.mainEnv})

	f, p := s.factory, s.projectID

	// Test creating project-level variable with single app-scope.
	_, stdErr, err := f.RunCombinedOutput("var:create", "-p", p, "-l", "p",
		"env:SCOPED", "--value", "val", "--app-scope", "app1")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Creating variable env:SCOPED")

	// Verify application_scope was set.
	out := f.Run("var:get", "-p", p, "-l", "p", "env:SCOPED", "-P", "application_scope")
	assert.Contains(t, out, "app1")

	// Test creating variable with multiple app scopes.
	_, _, err = f.RunCombinedOutput("var:create", "-p", p, "-l", "p",
		"env:MULTI", "--value", "val", "--app-scope", "app1", "--app-scope", "app2")
	assert.NoError(t, err)

	out = f.Run("var:get", "-p", p, "-l", "p", "env:MULTI", "-P", "application_scope")
	assert.Contains(t, out, "app1")
	assert.Contains(t, out, "app2")

	// Test validation rejects invalid app names (when deployment exists).
	_, stdErr, err = f.RunCombinedOutput("var:create", "-p", p, "-l", "p",
		"env:BAD", "--value", "val", "--app-scope", "nonexistent")
	assert.Error(t, err)
	assert.Contains(t, stdErr, "was not found")

	// Test updating app-scope.
	_, _, err = f.RunCombinedOutput("var:update", "-p", p, "-l", "p",
		"env:SCOPED", "--app-scope", "app2")
	assert.NoError(t, err)

	out = f.Run("var:get", "-p", p, "-l", "p", "env:SCOPED", "-P", "application_scope")
	assert.Contains(t, out, "app2")
}

func TestVariableCreateWithAppScopeNoDeployment(t *testing.T) {
	// Uses an environment without a deployment, so app-scope validation is skipped.
	s := setupVariableTest(t)
	s.apiHandler.SetEnvironments([]*mockapi.Environment{s.mainEnv})

	f, p := s.factory, s.projectID

	// Without a deployment, any app-scope value should be accepted.
	_, stdErr, err := f.RunCombinedOutput("var:create", "-p", p, "-l", "p",
		"env:ANY_APP", "--value", "val", "--app-scope", "anyapp")
	assert.NoError(t, err)
	assert.Contains(t, stdErr, "Creating variable env:ANY_APP")

	out := f.Run("var:get", "-p", p, "-l", "p", "env:ANY_APP", "-P", "application_scope")
	assert.Contains(t, out, "anyapp")
}
