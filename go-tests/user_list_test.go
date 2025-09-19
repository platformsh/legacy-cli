package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestUserList(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	projectID := mockapi.ProjectID()
	myUserID := "my-user-id"
	vendor := "test-vendor"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg("org-id-1", "org-1", "Org 1", myUserID, "flexible"),
	})
	apiHandler.SetProjects([]*mockapi.Project{
		makeProject(projectID, "org-id-1", vendor, "Project 1", "region-1"),
	})
	apiHandler.SetUserGrants([]*mockapi.UserGrant{
		{
			ResourceID:     "org-id-1",
			ResourceType:   "organization",
			OrganizationID: "org-id-1",
			UserID:         myUserID,
			Permissions:    []string{"admin"},
		},
		{
			ResourceID:     projectID,
			ResourceType:   "project",
			OrganizationID: "org-id-1",
			UserID:         myUserID,
			Permissions:    []string{"admin"},
		},
		{
			ResourceID:     projectID,
			ResourceType:   "project",
			OrganizationID: "org-id-1",
			UserID:         "user-id-2",
			Permissions:    []string{"viewer", "development:viewer"},
		},
		{
			ResourceID:     projectID,
			ResourceType:   "project",
			OrganizationID: "org-id-1",
			UserID:         "user-id-3",
			Permissions:    []string{"viewer", "production:viewer", "development:admin", "staging:contributor"},
		},
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assertTrimmed(t, `
+------------------------+-----------------+--------------+------------+
| Email address          | Name            | Project role | ID         |
+------------------------+-----------------+--------------+------------+
| my-user-id@example.com | User my-user-id | admin        | my-user-id |
| user-id-2@example.com  | User user-id-2  | viewer       | user-id-2  |
| user-id-3@example.com  | User user-id-3  | viewer       | user-id-3  |
+------------------------+-----------------+--------------+------------+
`, f.Run("users", "-p", projectID))

	assertTrimmed(t, `
Email address	Name	Project role	ID	Permissions
my-user-id@example.com	User my-user-id	admin	my-user-id	admin
user-id-2@example.com	User user-id-2	viewer	user-id-2	viewer, development:viewer
user-id-3@example.com	User user-id-3	viewer	user-id-3	viewer, production:viewer, development:admin, staging:contributor
`, f.Run("users", "-p", projectID, "--format", "plain", "--columns", "+perm%"))
}
