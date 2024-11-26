package tests

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestAuthInfo(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{
		ID:                  "my-user-id",
		Deactivated:         false,
		Namespace:           "ns",
		Username:            "my-username",
		FirstName:           "Foo",
		LastName:            "Bar",
		Email:               "my-user@example.com",
		EmailVerified:       true,
		Picture:             "https://example.com/profile.png",
		Country:             "NO",
		PhoneNumberVerified: true,
		MFAEnabled:          true,
	})

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	run := runnerWithAuth(t, apiServer.URL, authServer.URL)

	assert.Equal(t, strings.TrimLeft(`
+-----------------------+---------------------+
| Property              | Value               |
+-----------------------+---------------------+
| id                    | my-user-id          |
| first_name            | Foo                 |
| last_name             | Bar                 |
| username              | my-username         |
| email                 | my-user@example.com |
| phone_number_verified | true                |
+-----------------------+---------------------+
`, "\n"), run("auth:info", "-v", "--refresh"))

	assert.Equal(t, "my-user-id\n", run("auth:info", "-P", "id"))
}
