FROM  php:7.3-cli

ARG USER_ID
ARG GROUP_ID

RUN apt-get update -q -y && apt-get install -y \
    wget \
    openssh-client \
    git \
    unzip \
    && docker-php-ext-install -j$(nproc) pcntl \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid ${GROUP_ID:-1000} psh; adduser --uid ${USER_ID:-1000} --gid ${GROUP_ID:-1000} --disabled-password --gecos "" psh

ADD docker/install_composer.sh /install_composer.sh
RUN /install_composer.sh; rm /install_composer.sh

USER psh

RUN mkdir /home/psh/.ssh

WORKDIR /home/psh/platformsh-cli
