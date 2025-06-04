package tests

import (
	"net/http/httptest"
	"strconv"
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

	// Generate a longer list of activities.
	var activities = make([]*mockapi.Activity, 30)
	for i := range activities {
		num := i + 1
		createdAt := aprilFoolsDay10am.Add(time.Duration(i) * time.Minute)
		varName := "X" + strconv.Itoa(num)
		activities[i] = &mockapi.Activity{
			ID:                "act" + strconv.Itoa(num),
			Type:              "environment.variable.create",
			State:             "complete",
			Result:            "success",
			CompletionPercent: 100,
			Project:           projectID,
			Environments:      []string{"main"},
			Description:       "<user>Mock User</user> created variable <variable>" + varName + "</variable> on environment <environment>main</environment>",
			Text:              "Mock User created variable " + varName + " on environment main",
			CreatedAt:         createdAt,
			UpdatedAt:         createdAt,
		}
	}
	apiHandler.SetProjectActivities(projectID, activities)

	assertTrimmed(t, `
ID	Created	Description	Progress	State	Result
act30	2014-04-01T10:29:00+00:00	Mock User created variable X30 on environment main	100%	complete	success
act29	2014-04-01T10:28:00+00:00	Mock User created variable X29 on environment main	100%	complete	success
act28	2014-04-01T10:27:00+00:00	Mock User created variable X28 on environment main	100%	complete	success
act27	2014-04-01T10:26:00+00:00	Mock User created variable X27 on environment main	100%	complete	success
act26	2014-04-01T10:25:00+00:00	Mock User created variable X26 on environment main	100%	complete	success`,
		f.Run("act", "-p", projectID, "-e", ".", "--format", "plain", "--limit", "5"))

	assertTrimmed(t, `
ID	Created	Description	Progress	State	Result
act30	2014-04-01T10:29:00+00:00	Mock User created variable X30 on environment main	100%	complete	success
act29	2014-04-01T10:28:00+00:00	Mock User created variable X29 on environment main	100%	complete	success
act28	2014-04-01T10:27:00+00:00	Mock User created variable X28 on environment main	100%	complete	success
act27	2014-04-01T10:26:00+00:00	Mock User created variable X27 on environment main	100%	complete	success
act26	2014-04-01T10:25:00+00:00	Mock User created variable X26 on environment main	100%	complete	success
act25	2014-04-01T10:24:00+00:00	Mock User created variable X25 on environment main	100%	complete	success
act24	2014-04-01T10:23:00+00:00	Mock User created variable X24 on environment main	100%	complete	success
act23	2014-04-01T10:22:00+00:00	Mock User created variable X23 on environment main	100%	complete	success
act22	2014-04-01T10:21:00+00:00	Mock User created variable X22 on environment main	100%	complete	success
act21	2014-04-01T10:20:00+00:00	Mock User created variable X21 on environment main	100%	complete	success
act20	2014-04-01T10:19:00+00:00	Mock User created variable X20 on environment main	100%	complete	success
act19	2014-04-01T10:18:00+00:00	Mock User created variable X19 on environment main	100%	complete	success`,
		f.Run("act", "-p", projectID, "-e", ".", "--format", "plain", "--limit", "12"))
}
