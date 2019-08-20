FROM ubuntu:latest

ARG USER_ID
ARG GROUP_ID

RUN apt-get update -q -y && apt-get install -y php php-xml php-zip unzip openssh-client composer

RUN usermod -u ${USER_ID:-1000} www-data && groupmod -g ${GROUP_ID:-1000} www-data && mkdir /var/www && chown www-data:www-data /var/www

ENV COMPOSER_HOME="/tmp/.composer"

RUN mkdir /var/cli

USER www-data

WORKDIR /var/cli
