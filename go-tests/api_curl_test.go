package tests

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os/exec"
	"strings"
	"testing"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"
	"github.com/stretchr/testify/assert"
)

func TestApiCurlCommand(t *testing.T) {
	validToken := "valid-token"

	mux := chi.NewMux()
	if testing.Verbose() {
		mux.Use(middleware.DefaultLogger)
	}
	mux.Use(func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if !strings.HasPrefix(r.URL.Path, "/oauth2") {
				if r.Header.Get("Authorization") != "Bearer "+validToken {
					w.WriteHeader(http.StatusUnauthorized)
					_ = json.NewEncoder(w).Encode(map[string]any{"error": "invalid_token", "error_description": "Invalid access token."})
					return
				}
			}
			next.ServeHTTP(w, r)
		})
	})
	var tokenFetches int
	mux.Post("/oauth2/token", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		tokenFetches++
		_ = json.NewEncoder(w).Encode(map[string]any{"access_token": validToken, "expires_in": 900, "token_type": "bearer"})
	})
	mux.Get("/users/me", func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{"id": "userID", "email": "me@example.com"})
	})
	mux.Get("/fake-api-path", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("success"))
	})
	mockServer := httptest.NewServer(mux)
	defer mockServer.Close()

	f := newCommandFactory(t, mockServer.URL, mockServer.URL)

	// Load the first token.
	assert.Equal(t, "success", f.Run("api:curl", "/fake-api-path"))
	assert.Equal(t, 1, tokenFetches)

	// Revoke the access token and try the command again.
	// The old token should be considered invalid, so the API call should return 401,
	// and then the CLI should refresh the token and retry.
	validToken = "new-valid-token"
	assert.Equal(t, "success", f.Run("api:curl", "/fake-api-path"))
	assert.Equal(t, 2, tokenFetches)

	assert.Equal(t, "success", f.Run("api:curl", "/fake-api-path"))
	assert.Equal(t, 2, tokenFetches)

	// If --no-retry-401 and --fail are provided then the command should return exit code 22.
	validToken = "another-new-valid-token"
	stdOut, _, err := f.RunCombinedOutput("api:curl", "/fake-api-path", "--no-retry-401", "--fail")
	exitErr := &exec.ExitError{}
	assert.ErrorAs(t, err, &exitErr)
	assert.Equal(t, 22, exitErr.ExitCode())
	assert.Empty(t, stdOut)
	assert.Equal(t, 2, tokenFetches)
}
