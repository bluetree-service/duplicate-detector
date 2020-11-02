FROM php:7.4-cli-alpine

COPY . /duplicate-detector/
RUN rm /duplicate-detector/Dockerfile

RUN ln -s /duplicate-detector/bin/detector /usr/local/bin/detector

WORKDIR /
