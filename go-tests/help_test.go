package tests

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestHelp(t *testing.T) {
	f := newCommandFactory(t, "", "")

	assert.Contains(t, f.Run("help", "pro"),
		"platform-test projects [--pipe] [--region REGION] [--title TITLE] [--my] [--refresh REFRESH] [--sort SORT] [--reverse] [--page PAGE] [-c|--count COUNT] [-o|--org ORG] [--org-type ORG-TYPE] [--format FORMAT] [--columns COLUMNS] [--no-header] [--date-fmt DATE-FMT]")

	actListHelp := f.Run("help", "act", "--format", "json")
	var helpData struct {
		Examples []struct {
			CommandLine string `json:"commandLine"`
			Description string `json:"description"`
		} `json:"examples"`
	}
	err := json.Unmarshal([]byte(actListHelp), &helpData)
	require.NoError(t, err)
	assert.NotEmpty(t, helpData.Examples)
}
