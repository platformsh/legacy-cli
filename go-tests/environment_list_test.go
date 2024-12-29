package tests

import (
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestEnvironmentList(t *testing.T) {
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
		},
	})
	apiHandler.SetEnvironments([]*mockapi.Environment{
		makeEnv(projectID, "main", "production", "active", nil),
		makeEnv(projectID, "staging", "staging", "active", "main"),
		makeEnv(projectID, "dev", "development", "active", "staging"),
		makeEnv(projectID, "fix", "development", "inactive", "dev"),
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assertTrimmed(t, `
+-----------+---------+----------+-------------+
| ID        | Title   | Status   | Type        |
+-----------+---------+----------+-------------+
| main      | Main    | Active   | production  |
|   staging | Staging | Active   | staging     |
|     dev   | Dev     | Active   | development |
|       fix | Fix     | Inactive | development |
+-----------+---------+----------+-------------+
`, f.Run("environment:list", "-v", "-p", projectID))

	assertTrimmed(t, `
ID	Title	Status	Type
main	Main	Active	production
staging	Staging	Active	staging
dev	Dev	Active	development
fix	Fix	Inactive	development
`, f.Run("environment:list", "-v", "-p", projectID, "--format", "plain"))

	assertTrimmed(t, `
ID	Title	Status	Type
main	Main	Active	production
staging	Staging	Active	staging
dev	Dev	Active	development
`, f.Run("environment:list", "-v", "-p", projectID, "--format", "plain", "--no-inactive"))

	assert.Equal(t, "fix\n", f.Run("environment:list", "-v", "-p", projectID, "--pipe", "--status=inactive"))
}

func makeEnv(projectID, name, envType, status string, parent any) *mockapi.Environment {
	created, _ := time.Parse(time.RFC3339, "2014-04-01T10:00:00Z")
	updated, _ := time.Parse(time.RFC3339, "2014-04-01T11:00:00Z")

	return &mockapi.Environment{
		ID:          name,
		Name:        name,
		MachineName: name + "-xyz",
		Title:       strings.ToTitle(name[:1]) + name[1:],
		Parent:      parent,
		Type:        envType,
		Status:      status,
		Project:     projectID,
		CreatedAt:   created,
		UpdatedAt:   updated,
		Links: mockapi.MakeHALLinks(
			"self=/projects/"+url.PathEscape(projectID)+"/environments/"+url.PathEscape(name),
			"#edit=/projects/"+url.PathEscape(projectID)+"/environments/"+url.PathEscape(name),
		),
	}
}
