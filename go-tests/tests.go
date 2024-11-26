// Package tests contains integration tests, which run the CLI as a shell command and verify its output.
//
// A TEST_CLI_PATH environment variable can be provided to override the path to a
// CLI executable. It defaults to `platform` in the repository root.
package tests

import (
	"bytes"
	"os"
	"os/exec"
	"path/filepath"
	"testing"

	"github.com/stretchr/testify/require"

	"github.com/platformsh/cli/pkg/mockapi"
)

var _validatedCommand string

// The legacy CLI identifier expects project IDs to be alphanumeric.
// See: https://github.com/platformsh/legacy-cli/blob/main/src/Service/Identifier.php#L75
const mockProjectID = "abcdefg123456"

func getCommandName(t *testing.T) string {
	if testing.Short() {
		t.Skip("skipping integration test due to -short flag")
	}
	if _validatedCommand != "" {
		return _validatedCommand
	}
	candidate := os.Getenv("TEST_CLI_PATH")
	if candidate != "" {
		_, err := os.Stat(candidate)
		require.NoError(t, err)
	} else {
		matches, _ := filepath.Glob("../bin/platform")
		if len(matches) == 0 {
			t.Skipf("skipping integration tests: CLI not found matching path: %s", "../bin/platform")
			return ""
		}
		c, err := filepath.Abs(matches[0])
		require.NoError(t, err)
		candidate = c
	}
	versionCmd := exec.Command(candidate, "--version")
	versionCmd.Env = testEnv()
	output, err := versionCmd.Output()
	require.NoError(t, err, "running '--version' must succeed under the CLI at: %s", candidate)
	require.Contains(t, string(output), "Platform Test CLI ")
	t.Logf("Validated CLI command %s", candidate)
	_validatedCommand = candidate
	return _validatedCommand
}

func command(t *testing.T, args ...string) *exec.Cmd {
	cmd := exec.Command(getCommandName(t), args...) //nolint:gosec
	cmd.Env = testEnv()
	cmd.Dir = os.TempDir()
	if testing.Verbose() {
		cmd.Stderr = os.Stderr
	}
	return cmd
}

func authenticatedCommand(t *testing.T, apiURL, authURL string, args ...string) *exec.Cmd {
	cmd := command(t, args...)
	cmd.Env = append(
		cmd.Env,
		EnvPrefix+"API_BASE_URL="+apiURL,
		EnvPrefix+"API_AUTH_URL="+authURL,
		EnvPrefix+"TOKEN="+mockapi.ValidAPITokens[0],
	)
	return cmd
}

// runnerWithAuth returns a function to authenticate and run a CLI command, returning stdout output.
// This asserts that the command has not failed.
func runnerWithAuth(t *testing.T, apiURL, authURL string) func(args ...string) string {
	return func(args ...string) string {
		cmd := authenticatedCommand(t, apiURL, authURL, args...)
		t.Log("Running:", cmd)
		b, err := cmd.Output()
		require.NoError(t, err)
		return string(b)
	}
}

// runnerCombinedOutput returns a function to authenticate and run a CLI command, returning combined output.
func runnerCombinedOutput(t *testing.T, apiURL, authURL string) func(args ...string) (string, error) {
	return func(args ...string) (string, error) {
		cmd := authenticatedCommand(t, apiURL, authURL, args...)
		var b bytes.Buffer
		cmd.Stdout = &b
		cmd.Stderr = &b
		t.Log("Running:", cmd)
		err := cmd.Run()
		return b.String(), err
	}
}

const EnvPrefix = "TEST_CLI_"

func testEnv() []string {
	configPath, err := filepath.Abs("config.yaml")
	if err != nil {
		panic(err)
	}
	return append(
		os.Environ(),
		"COLUMNS=120",
		"CLI_CONFIG_FILE="+configPath,
		EnvPrefix+"NO_INTERACTION=1",
		EnvPrefix+"VERSION=1.0.0",
		EnvPrefix+"HOME="+os.TempDir(),
		"TZ=UTC",
	)
}
