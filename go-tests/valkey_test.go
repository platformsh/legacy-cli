package tests

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"testing"

	"golang.org/x/crypto/ssh"

	"github.com/platformsh/cli/pkg/mockapi"
	"github.com/platformsh/cli/pkg/mockssh"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestValkey(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "my-user-id"

	sshServer, err := mockssh.NewServer(t, authServer.URL+"/ssh/authority")
	if err != nil {
		t.Fatal(err)
	}

	relationships := map[string]any{
		"cache": []map[string]any{{
			"username": nil,
			"host":     "cache.internal",
			"path":     nil,
			"query":    url.Values{},
			"password": nil,
			"port":     6379,
			"service":  "cache",
			"scheme":   "valkey",
			"type":     "valkey:8.0",
			"public":   false,
		}},
	}
	relationshipsJSON, err := json.Marshal(relationships)
	require.NoError(t, err)

	execHandler := mockssh.ExecHandler(t.TempDir(), []string{
		"PLATFORM_RELATIONSHIPS=" + base64.StdEncoding.EncodeToString(relationshipsJSON),
	})

	sshServer.CommandHandler = func(conn ssh.ConnMetadata, command string, io mockssh.CommandIO) int {
		if strings.HasPrefix(command, "valkey-cli") {
			_, _ = fmt.Fprint(io.StdOut, "Received command: "+command)
			return 0
		}

		return execHandler(conn, command, io)
	}
	t.Cleanup(func() {
		if err := sshServer.Stop(); err != nil {
			t.Error(err)
		}
	})

	projectID := mockapi.ProjectID()

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

	assert.Equal(t, "Received command: valkey-cli -h cache.internal -p 6379 ping",
		f.Run("valkey", "-p", projectID, "-e", ".", "ping"))

	assert.Equal(t, "Received command: valkey-cli -h cache.internal -p 6379 --scan",
		f.Run("valkey", "-p", projectID, "-e", ".", "--", "--scan"))

	assert.Equal(t, "Received command: valkey-cli -h cache.internal -p 6379 --scan --pattern '*-11*'",
		f.Run("valkey", "-p", projectID, "-e", ".", "--", "--scan --pattern '*-11*'"))
}
