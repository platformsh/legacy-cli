FROM gitpod/workspace-full:latest

# Set PHP 7.4 - Gitpod defaults to 8.1 otherwise
RUN sudo update-alternatives --set php $(which php7.4)
