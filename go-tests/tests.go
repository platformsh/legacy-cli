// Package tests contains integration tests, which run the CLI as a shell command and verify its output.
//
// A TEST_CLI_PATH environment variable can be provided to override the path to a
// CLI executable. It defaults to `bin/platform` in the repository root.
package tests

import (
	"bytes"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/platformsh/cli/pkg/mockapi"
)

var _validatedCommand string

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

type cmdFactory struct {
	t        *testing.T
	apiURL   string
	authURL  string
	extraEnv []string
}

func newCommandFactory(t *testing.T, apiURL, authURL string) *cmdFactory {
	return &cmdFactory{t: t, apiURL: apiURL, authURL: authURL}
}

// Run runs a command, asserts that it did not error, and returns its normal (stdout) output.
func (f *cmdFactory) Run(args ...string) string {
	cmd := f.buildCommand(args...)
	f.t.Log("Running:", cmd)
	b, err := cmd.Output()
	require.NoError(f.t, err)
	return string(b)
}

// RunCombinedOutput runs a command and returns its stdout, stderr and the error.
func (f *cmdFactory) RunCombinedOutput(args ...string) (string, string, error) {
	cmd := f.buildCommand(args...)
	var stdOutBuffer bytes.Buffer
	var stdErrBuffer bytes.Buffer
	cmd.Stdout = &stdOutBuffer
	cmd.Stderr = &stdErrBuffer
	if testing.Verbose() {
		cmd.Stderr = io.MultiWriter(&stdErrBuffer, os.Stderr)
	}
	f.t.Log("Running:", cmd)
	err := cmd.Run()
	return stdOutBuffer.String(), stdErrBuffer.String(), err
}

func (f *cmdFactory) buildCommand(args ...string) *exec.Cmd {
	cmd := exec.Command(getCommandName(f.t), args...) //nolint:gosec
	cmd.Env = testEnv()
	cmd.Dir = os.TempDir()
	if testing.Verbose() {
		cmd.Stderr = os.Stderr
	}
	if f.apiURL != "" {
		cmd.Env = append(cmd.Env, EnvPrefix+"API_BASE_URL="+f.apiURL)
	}
	if f.authURL != "" {
		cmd.Env = append(cmd.Env, EnvPrefix+"API_AUTH_URL="+f.authURL, EnvPrefix+"TOKEN="+mockapi.ValidAPITokens[0])
	}
	cmd.Env = append(cmd.Env, f.extraEnv...)
	return cmd
}

func assertTrimmed(t *testing.T, expected, actual string) {
	assert.Equal(t, strings.TrimSpace(expected), strings.TrimSpace(actual))
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
