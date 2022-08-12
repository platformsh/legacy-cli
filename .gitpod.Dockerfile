FROM docker pull gitpod/workspace-full:latest

RUN sudo update-alternatives --set php $(which php7.4)