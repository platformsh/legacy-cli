package tests

import (
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestBackupList(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	projectID := mockapi.ProjectID()

	apiHandler.SetProjects([]*mockapi.Project{
		{
			ID:            projectID,
			DefaultBranch: "main",
			Links: mockapi.MakeHALLinks(
				"self=/projects/"+projectID,
				"environments=/projects/"+projectID+"/environments",
			),
		},
	})
	main := makeEnv(projectID, "main", "production", "active", nil)
	main.Links["backups"] = mockapi.HALLink{HREF: "/projects/" + projectID + "//environments/main/backups"}
	apiHandler.SetEnvironments([]*mockapi.Environment{main})

	created1, err := time.Parse(time.RFC3339, "2014-04-01T10:00:00+01:00")
	require.NoError(t, err)
	created2, err := time.Parse(time.RFC3339, "2015-04-01T10:00:00+01:00")
	require.NoError(t, err)

	apiHandler.SetProjectBackups(projectID, []*mockapi.Backup{
		{
			ID:            "123",
			EnvironmentID: "main",
			Status:        "CREATED",
			Safe:          true,
			Restorable:    true,
			Automated:     false,
			CommitID:      "foo",
			CreatedAt:     created1,
		},
		{
			ID:            "456",
			EnvironmentID: "main",
			Status:        "CREATED",
			Safe:          false,
			Restorable:    true,
			Automated:     true,
			CommitID:      "bar",
			CreatedAt:     created2,
		},
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assertTrimmed(t, `
+---------------------------+-----------+------------+
| Created                   | Backup ID | Restorable |
+---------------------------+-----------+------------+
| 2015-04-01T09:00:00+00:00 | 456       | true       |
| 2014-04-01T09:00:00+00:00 | 123       | true       |
+---------------------------+-----------+------------+
`, f.Run("backups", "-p", projectID, "-e", "."))

	assertTrimmed(t, `
+---------------------------+-----------+------------+-----------+-----------+
| Created                   | Backup ID | Restorable | Automated | Commit ID |
+---------------------------+-----------+------------+-----------+-----------+
| 2015-04-01T09:00:00+00:00 | 456       | true       | true      | bar       |
| 2014-04-01T09:00:00+00:00 | 123       | true       | false     | foo       |
+---------------------------+-----------+------------+-----------+-----------+
`, f.Run("backups", "-p", projectID, "-e", ".", "--columns", "+automated,commit_id"))
}

func TestBackupCreate(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	projectID := mockapi.ProjectID()

	apiHandler.SetProjects([]*mockapi.Project{
		{
			ID:            projectID,
			DefaultBranch: "main",
			Links: mockapi.MakeHALLinks(
				"self=/projects/"+projectID,
				"environments=/projects/"+projectID+"/environments",
			),
		},
	})
	main := makeEnv(projectID, "main", "production", "active", nil)
	main.Links["backups"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main//backups"}
	main.Links["#backup"] = mockapi.HALLink{HREF: "/projects/" + projectID + "/environments/main/backups"}
	apiHandler.SetEnvironments([]*mockapi.Environment{main})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	f.Run("backup", "-p", projectID, "-e", ".")

	assert.NotEmpty(t, f.Run("backups", "-p", projectID, "-e", "."))
}
