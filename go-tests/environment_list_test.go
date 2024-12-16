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

	apiHandler.SetProjects([]*mockapi.Project{
		{
			ID: mockProjectID,
			Links: mockapi.MakeHALLinks(
				"self=/projects/"+mockProjectID,
				"environments=/projects/"+mockProjectID+"/environments",
			),
		},
	})
	apiHandler.SetEnvironments([]*mockapi.Environment{
		makeEnv(mockProjectID, "main", "production", "active", nil),
		makeEnv(mockProjectID, "staging", "staging", "active", "main"),
		makeEnv(mockProjectID, "dev", "development", "active", "staging"),
		makeEnv(mockProjectID, "fix", "development", "inactive", "dev"),
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
`, f.Run("environment:list", "-v", "-p", mockProjectID))

	assertTrimmed(t, `
ID	Title	Status	Type
main	Main	Active	production
staging	Staging	Active	staging
dev	Dev	Active	development
fix	Fix	Inactive	development
`, f.Run("environment:list", "-v", "-p", mockProjectID, "--format", "plain"))

	assertTrimmed(t, `
ID	Title	Status	Type
main	Main	Active	production
staging	Staging	Active	staging
dev	Dev	Active	development
`, f.Run("environment:list", "-v", "-p", mockProjectID, "--format", "plain", "--no-inactive"))

	assert.Equal(t, "fix\n", f.Run("environment:list", "-v", "-p", mockProjectID, "--pipe", "--status=inactive"))
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
