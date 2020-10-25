FROM php:7.4-cli

COPY . /duplicate-detector/
RUN rm /duplicate-detector/Dockerfile

RUN ln -s /duplicate-detector/bin/duplicate-detector /usr/local/bin/duplicate-detector

WORKDIR /
