package tests

import (
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestOrgList(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	myUserID := "user-id-1"

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetMyUser(&mockapi.User{ID: myUserID})
	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg("org-id-1", "acme", "ACME Inc.", myUserID),
		makeOrg("org-id-2", "four-seasons", "Four Seasons Total Landscaping", myUserID),
		makeOrg("org-id-3", "duff", "Duff Beer", "user-id-2"),
	})

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	f := newCommandFactory(t, apiServer.URL, authServer.URL)

	assertTrimmed(t, `
+--------------+--------------------------------+-----------------------+
| Name         | Label                          | Owner email           |
+--------------+--------------------------------+-----------------------+
| acme         | ACME Inc.                      | user-id-1@example.com |
| duff         | Duff Beer                      | user-id-2@example.com |
| four-seasons | Four Seasons Total Landscaping | user-id-1@example.com |
+--------------+--------------------------------+-----------------------+
`, f.Run("orgs"))

	assertTrimmed(t, `
Name	Label	Owner email
acme	ACME Inc.	user-id-1@example.com
duff	Duff Beer	user-id-2@example.com
four-seasons	Four Seasons Total Landscaping	user-id-1@example.com
`, f.Run("orgs", "--format", "plain"))

	assertTrimmed(t, `
org-id-1,acme
org-id-3,duff
org-id-2,four-seasons
`, f.Run("orgs", "--format", "csv", "--columns", "id,name", "--no-header"))
}

func makeOrg(id, name, label, owner string) *mockapi.Org {
	return &mockapi.Org{
		ID:           id,
		Name:         name,
		Label:        label,
		Owner:        owner,
		Capabilities: []string{},
		Links:        mockapi.MakeHALLinks("self=/organizations/" + url.PathEscape(id)),
	}
}
