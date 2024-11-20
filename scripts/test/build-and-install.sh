#!/usr/bin/env bash
# Tests building and installing a test version of the CLI.
# This must be run from the repository root.

export version=test

# Ensure Composer dependencies including Box.
if [ ! -d vendor ]; then
  composer install
fi
if [ ! -d vendor-bin/box/vendor ]; then
  composer -d vendor-bin/box install
fi

function cleanup {
  rm tmp-platform.phar
  rm tmp-manifest.json
}
trap cleanup EXIT

# Build the CLI.
./bin/platform self:build --no-composer-rebuild --yes --replace-version "$version" --output tmp-platform.phar

# Create a manifest file.
export sha256="$(shasum -a 256 tmp-platform.phar | cut -f1 -d' ')"
cat <<EOF > tmp-manifest.json
[
  {
    "version": "$version",
    "sha256": "$sha256",
    "name": "platform.phar",
    "url": "tmp-platform.phar"
  }
]
EOF

# Run the installer.
cat ./dist/installer.php | php -- --manifest ./tmp-manifest.json --dev
