package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/stretchr/testify/assert"
)

func TestEnvironmentDeployType(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	projectID := mockapi.ProjectID()
	apiHandler.SetProjects([]*mockapi.Project{
		{
			ID: projectID,
			Links: mockapi.MakeHALLinks(
				"self=/projects/"+projectID,
				"environments=/projects/"+projectID+"/environments",
			),
			DefaultBranch: "main",
		},
	})
	main := makeEnv(projectID, "main", "production", "active", nil)
	main.SetSetting("enable_manual_deployments", false)
	main.Links["#deploy"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/deploy"}
	apiHandler.SetEnvironments([]*mockapi.Environment{main, makeEnv(projectID, "dev", "development", "inactive", nil)})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)
	f.Run("cc")

	_, stdErr, _ := f.RunCombinedOutput("environment:deploy:type", "-p", projectID, "-e", "main")
	assert.Equal(t, `Deployment type: automatic

Hint: Choose automatic (default) if you want your changes to be deployed immediately as they are made.
Choose manual to have code, variables, domains, and settings changes staged until you trigger a deployment.
`, stdErr)

	_, stdErr, _ = f.RunCombinedOutput("env:deploy:type", "automatic", "-p", projectID, "-e", "main")
	assert.Equal(t, stdErr, "The deployment type is already automatic.\n")

	_, _, err := f.RunCombinedOutput("env:deploy:type", "invalid", "-p", projectID, "-e", "main")
	assert.Error(t, err)

	_, stdErr, _ = f.RunCombinedOutput("env:deploy:type", "manual", "-p", projectID, "-e", "main")
	assert.Equal(t, `Success!
Deployment type: manual

Hint: Choose automatic (default) if you want your changes to be deployed immediately as they are made.
Choose manual to have code, variables, domains, and settings changes staged until you trigger a deployment.
`, stdErr)

	apiHandler.SetProjectActivities(projectID, []*mockapi.Activity{
		{
			ID:                "act1",
			Type:              "environment.push",
			State:             "staged",
			Result:            "success",
			CompletionPercent: 100,
			Project:           projectID,
			Environments:      []string{"main"},
			Description:       "<user>Mock User</user> pushed to <environment>main</environment>",
			Text:              "Mock User pushed to main",
		},
	})

	_, stdErr, _ = f.RunCombinedOutput("env:deploy:type", "automatic", "-p", projectID, "-e", "main")
	assert.Equal(t, `Updating this setting will immediately start a deployment to apply all staged changes.
Are you sure you want to proceed? [Y/n] y
Success!
Deployment type: automatic

Hint: Choose automatic (default) if you want your changes to be deployed immediately as they are made.
Choose manual to have code, variables, domains, and settings changes staged until you trigger a deployment.
`, stdErr)

	_, stdErr, _ = f.RunCombinedOutput("env:deploy:type", "manual", "-p", projectID, "-e", "dev")
	assert.Equal(t, "Manual deployment type is not available for this environment.\n", stdErr)
}
