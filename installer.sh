#!/usr/bin/env bash
#
# Platform.sh CLI installer
# -------------------------
# This installs the Platform.sh CLI in the user's home directory so that is is
# available as the command 'platform'. It updates the CLI if it is already
# installed.

set -e

COMPOSER_CMD=composer
PROFILE=${PROFILE:-''}

main() {
	local VERSION=${1:-'1.*'}
	local CLI_DIR="${HOME}/.platformsh-cli"
	local FIRST_INSTALL=0

	[ ! -d "$CLI_DIR" ] && FIRST_INSTALL=1 && mkdir "$CLI_DIR"

	_check_requirements

	_install_composer "$CLI_DIR"

	${COMPOSER_CMD} --version

	echo "Updating platformsh/cli"
	cd "$CLI_DIR"
	${COMPOSER_CMD} require \
		--no-interaction --no-progress --update-no-dev \
		"platformsh/cli $VERSION"

	_update_shrc "$CLI_DIR"

	local PLATFORM="${CLI_DIR}/vendor/bin/platform"

	if [ "$FIRST_INSTALL" = 1 ]; then
		echo "Installed successfully: $(${PLATFORM} --version)"
		echo
		echo "Run this command to set up your shell:"
		echo "    source ${CLI_DIR}/platform.rc"
		echo
		echo "Then type 'platform' to start using the Platform.sh CLI"
	else
		echo "Updated successfully: $(${PLATFORM} --version)"
		echo "Type 'platform' to use the Platform.sh CLI"
	fi
}

_check_requirements() {
	local ERROR=0
	for COMMAND in php curl git; do
		if ! command -v "$COMMAND" >/dev/null 2>&1; then
			echo >&2 "Missing requirement: $COMMAND"
			ERROR=1
		fi
	done
	if ! php -m | grep -q curl; then
		echo >&2 "Missing PHP cURL support"
		ERROR=1
	fi
	return ${ERROR}
}

_install_composer() {
	local DIR=${1:-.}
	local COMPOSER_BIN="${DIR}/composer-bin"
	local PHAR="${COMPOSER_BIN}/composer"
	local COMPOSER_URL='https://getcomposer.org/installer'
	COMPOSER_CMD="php $PHAR"
	if [ ! -f ${PHAR} ]; then
		mkdir -p ${COMPOSER_BIN}
		if ! curl -sfS "${COMPOSER_URL}" -o "${DIR}/composer-installer.php"; then
			echo "Error: Failed to download Composer"
			return 1
		fi
		php "${DIR}/composer-installer.php" --install-dir="${COMPOSER_BIN}" --filename=composer
		rm "${DIR}/composer-installer.php"
	else
		${COMPOSER_CMD} self-update --quiet || true
	fi
}

_find_shrc() {
	[ ! -z "$PROFILE" ] && echo "$PROFILE" && return
	local DEFAULT="$HOME/.bashrc"
	local SHELL_NAME=${SHELL#*bin/}
	[ ! -z "$SHELL_NAME" ] && DEFAULT="${HOME}/.${SHELL_NAME}rc"
	local CANDIDATES="$DEFAULT $HOME/.bashrc $HOME/.bash_profile $HOME/.profile"
	local FILE=''
	for CANDIDATE in "$CANDIDATES"; do
		if [ -f "$CANDIDATE" ]; then
			FILE=${CANDIDATE}
			break
		fi
	done
	# If no file has been found, create one.
	if [ -z "$FILE" ]; then
		touch "${DEFAULT}"
		FILE=${DEFAULT}
	fi
	echo "$FILE"
}

_create_installer_rc() {
	local DIR=${1:-~/.platformsh-cli}
	local RC="${DIR}/platform.rc"
	cat > "$RC" << EOF
#!/usr/bin/env bash
COMPOSER_BIN='${DIR}/composer-bin'
[ -f "\${COMPOSER_BIN}/composer" ] && [[ ! :\$PATH: == *:\${COMPOSER_BIN}:* ]] && export PATH="\${COMPOSER_BIN}:\${PATH}"
PLATFORM_BIN='${DIR}/vendor/bin'
[ -f "\${PLATFORM_BIN}/platform" ] && [[ ! :\$PATH: == *:\${PLATFORM_BIN}:* ]] && export PATH="\${PLATFORM_BIN}:\${PATH}"

# Enable auto-completion.
SHELL_TYPE=''
# Bash autocompletion requires the _get_comp_words_by_ref function.
if command -v _get_comp_words_by_ref > /dev/null; then
    # Specify an explicit shell type if the function exists (so we are
    # using Bash) yet $SHELL was not defined.
    [ -z "\$SHELL" ] && SHELL_TYPE=bash
elif [[ "\$SHELL" == *bash ]]; then
    # Fail if the shell is Bash, yet the function does not exist.
    return
fi

HOOK=\$("\${PLATFORM_BIN}/platform" _completion -g -p platform --shell-type="\${SHELL_TYPE}" 2>/dev/null)
[ -z "\$HOOK" ] && return

# Try three methods of registering autocompletion.
# See https://github.com/stecman/symfony-console-completion#zero-config-use
if [[ "\${BASH_VERSION}" == 4* ]]; then
    source <(echo "\${HOOK}")
elif [ ! -z "\${BASH_VERSION}" ]; then
    eval "\${HOOK}"
elif [ ! -z "\$ZSH" ]; then
    echo "\${HOOK}" | source /dev/stdin
fi
EOF
	echo "${RC}"
}

_update_shrc() {
	local DIR=${1:-~/.platformsh-cli}
	local RC=$(_create_installer_rc "${DIR}")
	local FILE=$(_find_shrc)
	[ -z "$FILE" ] && echo "Error: Unable to find shell configuration file" && return 1
	if ! grep -qF "$RC" "$FILE"; then
		echo '. '"$RC"' 2>/dev/null || true' >> "$FILE"
		echo "Updated: $FILE"
	fi
}

main $@
