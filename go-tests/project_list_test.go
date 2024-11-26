package tests

import (
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestProjectList(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "my-user-id"
	otherUserID := "other-user-id"
	vendor := "test-vendor"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg("org-id-1", "org-1", "Org 1", myUserID),
		makeOrg("org-id-2", "org-2", "Org 2", otherUserID),
	})
	apiHandler.SetProjects([]*mockapi.Project{
		makeProject("project-id-1", "org-id-1", vendor, "Project 1", "region-1"),
		makeProject("project-id-2", "org-id-2", vendor, "Project 2", "region-2"),
		makeProject("project-id-3", "org-id-2", vendor, "Project 3", "region-2"),
		makeProject("project-other-vendor-3", "org-other-vendor", "acme", "Other Vendor's Project", "region-1"),
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
			ResourceID:     "project-id-1",
			ResourceType:   "project",
			OrganizationID: "org-id-1",
			UserID:         myUserID,
			Permissions:    []string{"admin"},
		},
		{
			ResourceID:     "project-id-2",
			ResourceType:   "project",
			OrganizationID: "org-id-2",
			UserID:         "user-id-2",
			Permissions:    []string{"admin"},
		},
		{
			ResourceID:     "project-id-2",
			ResourceType:   "project",
			OrganizationID: "org-id-2",
			UserID:         myUserID,
			Permissions:    []string{"viewer", "development:admin"},
		},
		{
			ResourceID:     "project-id-3",
			ResourceType:   "project",
			OrganizationID: "org-id-2",
			UserID:         myUserID,
			Permissions:    []string{"viewer", "development:contributor"},
		},
	})

	run := runnerWithAuth(t, apiServer.URL, authServer.URL)

	assert.Equal(t, strings.TrimLeft(`
+--------------+-----------+----------+--------------+
| ID           | Title     | Region   | Organization |
+--------------+-----------+----------+--------------+
| project-id-1 | Project 1 | region-1 | org-1        |
| project-id-2 | Project 2 | region-2 | org-2        |
| project-id-3 | Project 3 | region-2 | org-2        |
+--------------+-----------+----------+--------------+
`, "\n"), run("pro", "-v"))

	assert.Equal(t, strings.TrimLeft(`
ID	Title	Region	Organization
project-id-1	Project 1	region-1	org-1
project-id-2	Project 2	region-2	org-2
project-id-3	Project 3	region-2	org-2
`, "\n"), run("pro", "-v", "--format", "plain"))

	assert.Equal(t, strings.TrimLeft(`
ID,Organization ID
project-id-1,org-id-1
project-id-2,org-id-2
project-id-3,org-id-2
`, "\n"), run("pro", "-v", "--format", "csv", "--columns", "id,organization_id"))

	assert.Equal(t, strings.TrimLeft(`
ID	Title	Region	Organization
project-id-1	Project 1	region-1	org-1
`, "\n"), run("pro", "-v", "--format", "plain", "--my"))

	assert.Equal(t, strings.TrimLeft(`
project-id-1
project-id-2
project-id-3
`, "\n"), run("pro", "-v", "--pipe"))
}

func makeProject(id, org, vendor, title, region string) *mockapi.Project {
	return &mockapi.Project{
		ID:           id,
		Organization: org,
		Vendor:       vendor,
		Title:        title,
		Region:       region,
		Links:        mockapi.MakeHALLinks("self=/projects/" + url.PathEscape(id)),
	}
}
