package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/stretchr/testify/assert"
)

func TestEnvironmentInfo(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)

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

	prod := makeEnv(projectID, "main", "production", "active", nil)
	prod.SetSetting("enable_manual_deployments", true)
	apiHandler.SetEnvironments([]*mockapi.Environment{
		prod,
		makeEnv(projectID, "staging", "staging", "active", "main"),
	})

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	// TODO disable the cache?
	f.Run("cc")

	assertTrimmed(t, `
Property	Value
id	main
name	main
machine_name	main-xyz
title	Main
type	production
status	active
parent	null
project	`+projectID+`
created_at	2014-04-01T10:00:00+00:00
updated_at	2014-04-01T11:00:00+00:00
deployment_type	manual
`, f.Run("env:info", "-p", projectID, "-e", ".", "--format", "plain", "--refresh", "-vvv"))

	assert.Equal(t, "2014-04-01\n", f.Run("env:info", "-p", projectID, "-e", ".", "created_at", "--date-fmt", "Y-m-d"))

	assert.Equal(t, "Main\n", f.Run("env:info", "-p", projectID, "-e", ".", "title"))
	assert.Equal(t, "Staging\n", f.Run("env:info", "-p", projectID, "-e", "staging", "title"))

	f.Run("env:info", "-v", "-p", projectID, "-e", ".", "title", "New Title")

	assert.Equal(t, "New Title\n", f.Run("env:info", "-p", projectID, "-e", ".", "title"))
}
