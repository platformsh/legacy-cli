package tests

import (
	"bytes"
	"io"
	"net/http/httptest"
	"os"
	"os/exec"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/platformsh/cli/pkg/mockapi"
)

func TestProjectCreate(t *testing.T) {
	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiHandler.SetOrgs([]*mockapi.Org{
		makeOrg("cli-test-id", "cli-tests", "CLI Test Org", "my-user-id"),
	})

	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	title := "Test Project Title"
	region := "test-region"

	cmd := authenticatedCommand(t, apiServer.URL, authServer.URL,
		"project:create", "-v", "--region", region, "--title", title, "--org", "cli-tests")

	var stdErrBuf bytes.Buffer
	var stdOutBuf bytes.Buffer
	cmd.Stderr = &stdErrBuf
	if testing.Verbose() {
		cmd.Stderr = io.MultiWriter(&stdErrBuf, os.Stderr)
	}
	cmd.Stdout = &stdOutBuf
	t.Log("Running:", cmd)
	require.NoError(t, cmd.Run())

	// stdout should contain the project ID.
	projectID := strings.TrimSpace(stdOutBuf.String())
	assert.NotEmpty(t, projectID)

	// stderr should contain various messages.
	stderr := stdErrBuf.String()

	assert.Contains(t, stderr, "The estimated monthly cost of this project is: $1,000 USD")
	assert.Contains(t, stderr, "Region: "+region)
	assert.Contains(t, stderr, "Project ID: "+projectID)
	assert.Contains(t, stderr, "Project title: "+title)
}

func TestProjectCreate_CanCreateError(t *testing.T) {
	cases := []struct {
		orgName            string
		canCreateResponse  *mockapi.CanCreateResponse
		expectExitCode     int
		expectStderrEquals string
	}{
		{
			orgName: "need-cc",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message: "Please add a credit card for verification to continue with the project creation process.",
				RequiredAction: &mockapi.CanCreateRequiredAction{
					Action: "verification",
					Type:   "credit-card",
				},
			},
			// The API message is replaced for known verification methods.
			// The Console link is shown when the CLI's service.console_url is configured.
			expectStderrEquals: "Credit card verification is required before creating a project.\n\n" +
				"Please use Console to create your first project:\n" +
				"https://console.cli-tests.example.com\n",
		},
		{
			orgName: "need-phone",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message: "Please verify your phone number to continue with the project creation process.",
				RequiredAction: &mockapi.CanCreateRequiredAction{
					Action: "verification",
					Type:   "phone",
				},
			},
			expectStderrEquals: "Phone number verification is required before creating a project.\n\n" +
				"Please open the following URL in a browser to verify your phone number:\n" +
				"https://console.cli-tests.example.com/-/phone-verify\n",
		},
		{
			orgName: "need-support",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message: "Please contact support in order to proceed.",
				RequiredAction: &mockapi.CanCreateRequiredAction{
					Action: "verification",
					Type:   "ticket",
				},
			},
			expectStderrEquals: "Verification via a support ticket is required before creating a project.\n\n" +
				"Please open the following URL in a browser to create a ticket:\n" +
				"https://console.cli-tests.example.com/support\n",
		},
		{
			orgName: "inactive",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message: "You cannot create projects at this time because your organization is inactive.",
				RequiredAction: &mockapi.CanCreateRequiredAction{
					Action: "ticket",
				},
			},
			expectStderrEquals: "You cannot create projects at this time because your organization is inactive.\n\n" +
				"Please open the following URL in a browser to create a ticket:\n" +
				"https://console.cli-tests.example.com/support\n",
		},
		{
			orgName: "arbitrary-ticket-message",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message: "[Arbitrary message displayed alongside the ticket action]",
				RequiredAction: &mockapi.CanCreateRequiredAction{
					Action: "ticket",
				},
			},
			expectStderrEquals: "[Arbitrary message displayed alongside the ticket action]\n\n" +
				"Please open the following URL in a browser to create a ticket:\n" +
				"https://console.cli-tests.example.com/support\n",
		},
		{
			orgName: "overdue-invoice",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message:        "You cannot create projects at this time because your organization has an overdue invoice.",
				RequiredAction: &mockapi.CanCreateRequiredAction{Action: "billing_details"},
			},
			expectStderrEquals: "You cannot create projects at this time because your organization has an overdue invoice.\n\n" +
				"View or update billing details at:\n" +
				"https://console.cli-tests.example.com/overdue-invoice/-/billing\n",
		},
		{
			orgName: "trial-limit-reached",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message:        "You have reached the resources limit for this organization's trial.",
				RequiredAction: &mockapi.CanCreateRequiredAction{Action: "billing_details"},
			},
			expectStderrEquals: "You have reached the resources limit for this organization's trial.\n\n" +
				"View or update billing details at:\n" +
				"https://console.cli-tests.example.com/trial-limit-reached/-/billing\n",
		},
		{
			orgName: "billing-details-arbitrary-message",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message:        "[Arbitrary message displayed alongside billing_details action]",
				RequiredAction: &mockapi.CanCreateRequiredAction{Action: "billing_details"},
			},
			expectStderrEquals: "[Arbitrary message displayed alongside billing_details action]\n\n" +
				"View or update billing details at:\n" +
				"https://console.cli-tests.example.com/billing-details-arbitrary-message/-/billing\n",
		},
		{
			orgName: "license-activation",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message:        "Your organization license is being processed. Please try again in a few seconds",
				RequiredAction: &mockapi.CanCreateRequiredAction{Action: "retry", Type: "license_activation"},
			},
			expectStderrEquals: "Your organization license is being processed. Please try again in a few seconds\n",
		},
		{
			orgName: "new-unknown-verification-message",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message:        "Arbitrary verification message.",
				RequiredAction: &mockapi.CanCreateRequiredAction{Action: "verification"},
			},
			expectStderrEquals: "Arbitrary verification message.\n",
		},
		{
			orgName: "new-unknown-action-and-message",
			canCreateResponse: &mockapi.CanCreateResponse{
				Message:        "Arbitrary message for unknown action.",
				RequiredAction: &mockapi.CanCreateRequiredAction{Action: "unknown"},
			},
			expectStderrEquals: "Arbitrary message for unknown action.\n",
		},
	}

	authServer := mockapi.NewAuthServer(t)
	defer authServer.Close()

	apiHandler := mockapi.NewHandler(t)
	apiServer := httptest.NewServer(apiHandler)
	defer apiServer.Close()

	orgs := make([]*mockapi.Org, 0, len(cases))
	for _, c := range cases {
		orgs = append(orgs, makeOrg(c.orgName+"-id", c.orgName, c.orgName, "my-user-id"))
		apiHandler.SetCanCreate(c.orgName+"-id", c.canCreateResponse)
	}
	apiHandler.SetOrgs(orgs)

	for _, c := range cases {
		t.Run("can_create_"+c.orgName, func(t *testing.T) {
			cmd := authenticatedCommand(t, apiServer.URL, authServer.URL,
				"project:create", "-v", "--org", c.orgName)
			var stdErrBuf bytes.Buffer
			cmd.Stderr = &stdErrBuf
			if testing.Verbose() {
				cmd.Stderr = io.MultiWriter(&stdErrBuf, os.Stderr)
			}
			t.Log("Running:", cmd)
			err := cmd.Run()
			stdErr := stdErrBuf.String()
			ee := &exec.ExitError{}
			require.ErrorAs(t, err, &ee)
			assert.Equal(t, 1, ee.ExitCode())
			assert.Equal(t, c.expectStderrEquals, stdErr)
		})
	}
}
