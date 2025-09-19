package tests

import (
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestProjectInfo(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "my-user-id"
	vendor := "test-vendor"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg("org-id-1", "org-1", "Org 1", myUserID, "flexible"),
	})

	projectID := mockapi.ProjectID()
	created, err := time.Parse(time.RFC3339, "2014-04-01T10:00:00+01:00")
	require.NoError(t, err)

	apiHandler.SetProjects([]*mockapi.Project{
		{
			ID:           projectID,
			Title:        "Project 1",
			Region:       "region-1",
			Organization: "org-id-1",
			Vendor:       vendor,
			Repository: mockapi.ProjectRepository{
				URL: "git@git.region-1.example.com:mock-project.git",
			},
			DefaultBranch: "main",
			CreatedAt:     created,
			UpdatedAt:     created.Add(time.Second * 86400),
			Links: mockapi.MakeHALLinks(
				"self=/projects/"+url.PathEscape(projectID),
				"#edit=/projects/"+url.PathEscape(projectID),
			),
		},
	})

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	expectedLines := `Property	Value
id	` + projectID + `
title	Project 1
region	region-1
organization	org-id-1
vendor	test-vendor
repository	url: 'git@git.region-1.example.com:mock-project.git'
default_branch	main
created_at	2014-04-01T09:00:00+00:00
updated_at	2014-04-02T09:00:00+00:00
git	git@git.region-1.example.com:mock-project.git`

	output := f.Run("pro:info", "-p", projectID, "--format", "plain", "--refresh")

	for _, line := range strings.Split(expectedLines, "\n") {
		assert.True(t, strings.Contains(output, line+"\n"))
	}

	assert.Equal(t, "2014-04-01\n", f.Run("pro:info", "-p", projectID, "created_at", "--date-fmt", "Y-m-d"))

	assert.Equal(t, "Project 1\n", f.Run("pro:info", "-p", projectID, "title"))

	f.Run("pro:info", "-v", "-p", projectID, "title", "New Title")

	// TODO --refresh should not be needed here
	assert.Equal(t, "New Title\n", f.Run("pro:info", "-p", projectID, "title", "--refresh"))
}
