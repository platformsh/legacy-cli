package tests

import (
	"net/http/httptest"
	"testing"
	"time"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestEnvironmentDeploy(t *testing.T) {
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
	main.Links["#activities"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/activities"}
	main.Links["#deploy"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/deploy"}
	apiHandler.SetEnvironments([]*mockapi.Environment{main})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)
	f.Run("cc")

	assertTrimmed(t, `Nothing to deploy`, f.Run("env:deploy", "-p", projectID, "-e", "main"))

	created, _ := time.Parse(time.RFC3339, "2014-04-01T10:00:00Z")
	updated, _ := time.Parse(time.RFC3339, "2014-04-01T11:00:00Z")
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
			CreatedAt:         created,
			UpdatedAt:         updated,
		}, {
			ID:                "act2",
			Type:              "environment.variable.create",
			State:             "staged",
			Result:            "success",
			CompletionPercent: 100,
			Project:           projectID,
			Environments:      []string{"main"},
			Description:       "<user>Mock User</user> created variable <variable>X</variable> on environment <environment>main</environment>",
			Text:              "Mock User created variable X on environment main",
			CreatedAt:         created,
			UpdatedAt:         updated,
		},
	})

	assertTrimmed(t, `
You are about to deploy the following changes on the environment main:
+------+-------------------------+---------------------------------------------+-----------------------------+---------+
| ID   | Created                 | Description                                 | Type                        | Result  |
+------+-------------------------+---------------------------------------------+-----------------------------+---------+
| act1 | 2014-04-01T10:00:00+00: | Mock User pushed to main                    | environment.push            | success |
|      | 00                      |                                             |                             |         |
| act2 | 2014-04-01T10:00:00+00: | Mock User created variable X on environment | environment.variable.create | success |
|      | 00                      | main                                        |                             |         |
+------+-------------------------+---------------------------------------------+-----------------------------+---------+
`, f.Run("env:deploy", "-p", projectID, "-e", "main"))
}
