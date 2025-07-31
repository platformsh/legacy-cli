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

	expectedStderrPrefix := "Selected project: " + projectID + "\nSelected environment: main (type: production)\n\n"

	_, stdErr, _ := f.RunCombinedOutput("environment:deploy:type", "-p", projectID, "-e", "main")
	assert.Equal(t, expectedStderrPrefix+"Deployment type: automatic\n", stdErr)

	_, stdErr, _ = f.RunCombinedOutput("env:deploy:type", "automatic", "-p", projectID, "-e", "main")
	assert.Equal(t, expectedStderrPrefix+"The deployment type is already automatic.\n", stdErr)

	_, _, err := f.RunCombinedOutput("env:deploy:type", "invalid", "-p", projectID, "-e", "main")
	assert.Error(t, err)

	_, stdErr, _ = f.RunCombinedOutput("env:deploy:type", "manual", "-p", projectID, "-e", "main")
	assert.Equal(t, expectedStderrPrefix+"Changing the deployment type from automatic to manual...\n"+
		"The deployment type was updated successfully to: manual\n", stdErr)

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
	assert.Equal(t, expectedStderrPrefix+
		"Changing the deployment type from manual to automatic...\n"+
		"Updating this setting will immediately deploy staged changes.\n"+
		"Are you sure you want to continue? [Y/n] y\n"+
		"The deployment type was updated successfully to: automatic\n", stdErr)

	_, stdErr, _ = f.RunCombinedOutput("env:deploy:type", "manual", "-p", projectID, "-e", "dev")
	assert.Equal(t, "Selected project: "+projectID+"\nSelected environment: dev (type: development)\n\n"+
		"The manual deployment type is not available as the environment is not active.\n", stdErr)
}
