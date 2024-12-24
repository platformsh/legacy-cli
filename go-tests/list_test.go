package tests

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestList(t *testing.T) {
	f := newCommandFactory(t, "", "")

	output := f.Run("list")

	assert.NotEmpty(t, output)
	assert.Contains(t, output, "Available commands:")
	assert.Contains(t, output, "activity:list (activities, act)")
	assert.NotContains(t, output, "mount:size")

	output = f.Run("list", "--all")

	assert.NotEmpty(t, output)
	assert.Contains(t, output, "Available commands:")
	assert.Contains(t, output, "mount:size")
}
