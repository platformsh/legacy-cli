#!/usr/bin/env bash

echo "alias platfarm=\$GITPOD_REPO_ROOT/bin/platform" >> ~/.bashrc
echo "alias pf=platfarm" >> ~/.bashrc

source ~/.bashrc

echo -e "\e[32mYou can run Platform CLI commands using: platfarm or pf\e[0m"
echo -e "\e[1;33mNote: That's \"platfarm\" not \"platform\". The \"platform\" command needs to be available when you test builds and installs.\e[0m"