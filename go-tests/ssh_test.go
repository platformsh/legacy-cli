package tests

import (
	"net/http/httptest"
	"os/exec"
	"strconv"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/platformsh/cli/pkg/mockssh"
	"github.com/stretchr/testify/assert"
)

func TestSSH(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "my-user-id"

	sshServer, err := mockssh.NewServer(t, authServer.URL+"/ssh/authority")
	if err != nil {
		t.Fatal(err)
	}
	sshServer.RemoteEnv = []string{
		// TODO use this
		"PLATFORM_RELATIONSHIPS=e30K",
	}
	t.Cleanup(func() {
		if err := sshServer.Stop(); err != nil {
			t.Error(err)
		}
	})

	projectID := "aiyaikii1uere"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
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
	mainEnv := makeEnv(projectID, "main", "production", "active", nil)
	mainEnv.SetCurrentDeployment(&mockapi.Deployment{
		WebApps: map[string]mockapi.App{
			"app": {Name: "app", Type: "golang:1.23", Size: "M", Disk: 2048, Mounts: map[string]mockapi.Mount{}},
		},
		Services: map[string]mockapi.App{},
		Workers:  map[string]mockapi.Worker{},
		Routes:   mockRoutes(),
		Links:    mockapi.MakeHALLinks("self=/projects/" + projectID + "/environments/main/deployment/current"),
	})
	mainEnv.Links["pf:ssh:app:0"] = mockapi.HALLink{HREF: "ssh://app--0@ssh.cli-tests.example.com"}
	mainEnv.Links["pf:ssh:app:1"] = mockapi.HALLink{HREF: "ssh://app--1@ssh.cli-tests.example.com"}
	apiHandler.SetEnvironments([]*mockapi.Environment{
		mainEnv,
	})

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	f := newCommandFactory(t, apiServer.URL, authServer.URL)
	f.extraEnv = []string{
		EnvPrefix + "SSH_OPTIONS=HostName 127.0.0.1\nPort " + strconv.Itoa(sshServer.Port()),
		EnvPrefix + "SSH_HOST_KEYS=" + sshServer.HostKeyConfig(),
	}

	f.Run("cc")
	assert.Equal(t, sshServer.RemoteDir+"\n", f.Run("ssh", "-p", projectID, "-e", ".", "pwd"))

	_, stdErr, err := f.RunCombinedOutput("ssh", "-p", projectID, "-e", "main", "--instance", "2", "pwd")
	assert.Error(t, err)
	assert.Contains(t, stdErr, "Available instances: 0, 1")

	_, _, err = f.RunCombinedOutput("ssh", "-p", projectID, "-e", "main", "--instance", "1", "exit 2")
	var exitErr *exec.ExitError
	assert.ErrorAs(t, err, &exitErr)
	assert.Equal(t, 2, exitErr.ExitCode())
}
