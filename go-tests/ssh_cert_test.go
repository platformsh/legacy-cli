package tests

import (
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestSSHCerts(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "my-user-id"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	output := f.Run("ssh-cert:info")
	assert.Regexp(t, `(?m)^filename: .+?id_ed25519-cert\.pub$`, output)
	assert.Contains(t, output, "key_id: test-key-id\n")
	assert.Contains(t, output, "key_type: ssh-ed25519-cert-v01@openssh.com\n")
}
