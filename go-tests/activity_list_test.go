package tests

import (
	"net/http/httptest"
	"testing"
	"time"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestActivityList(t *testing.T) {
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
	main.Links["#activities"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/activities"}
	envs := []*mockapi.Environment{main}
	apiHandler.SetEnvironments(envs)

	aprilFoolsDay9am, _ := time.Parse(time.RFC3339, "2014-04-01T9:00:00Z")
	aprilFoolsDay10am, _ := time.Parse(time.RFC3339, "2014-04-01T10:00:00Z")
	aprilFoolsDay11am, _ := time.Parse(time.RFC3339, "2014-04-01T11:00:00Z")

	apiHandler.SetProjectActivities(projectID, []*mockapi.Activity{
		{
			ID:                "act1",
			Type:              "environment.variable.create",
			State:             "complete",
			Result:            "success",
			CompletionPercent: 100,
			Project:           projectID,
			Environments:      []string{"main"},
			Description:       "<user>Mock User</user> created variable <variable>X</variable> on environment <environment>main</environment>",
			Text:              "Mock User created variable X on environment main",
			CreatedAt:         aprilFoolsDay10am,
			UpdatedAt:         aprilFoolsDay11am,
		},
		{
			ID:                "act2",
			Type:              "project.variable.create",
			State:             "complete",
			Result:            "success",
			CompletionPercent: 100,
			Project:           projectID,
			Environments:      []string{},
			Description:       "<user>Mock User</user> created variable <variable>X</variable>",
			Text:              "Mock User created variable X",
			CreatedAt:         aprilFoolsDay9am,
			UpdatedAt:         aprilFoolsDay9am,
		},
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	f.Run("cc")

	assertTrimmed(t, `
+------+---------------------------+--------------------------------------------------+----------+----------+---------+
| ID   | Created                   | Description                                      | Progress | State    | Result  |
+------+---------------------------+--------------------------------------------------+----------+----------+---------+
| act1 | 2014-04-01T10:00:00+00:00 | Mock User created variable X on environment main | 100%     | complete | success |
+------+---------------------------+--------------------------------------------------+----------+----------+---------+
`, f.Run("act", "-p", projectID, "-e", "."))

	assertTrimmed(t, `
+------+----------------------+---------------------------------+----------+----------+---------+----------------+
| ID   | Created              | Description                     | Progress | State    | Result  | Environment(s) |
+------+----------------------+---------------------------------+----------+----------+---------+----------------+
| act1 | 2014-04-01T10:00:00+ | Mock User created variable X on | 100%     | complete | success | main           |
|      | 00:00                | environment main                |          |          |         |                |
| act2 | 2014-04-01T09:00:00+ | Mock User created variable X    | 100%     | complete | success |                |
|      | 00:00                |                                 |          |          |         |                |
+------+----------------------+---------------------------------+----------+----------+---------+----------------+
`, f.Run("act", "-p", projectID, "--all", "--limit", "20"))

	assertTrimmed(t, "complete", f.Run("act:get", "-p", projectID, "-e", ".", "act1", "-P", "state"))
	assertTrimmed(t, "2014-04-01T10:00:00+00:00", f.Run("act:get", "-p", projectID, "-e", ".", "act1", "-P", "created_at"))
}
