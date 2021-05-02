FROM php:7.4-cli-alpine

COPY . /duplicate-detector/
RUN rm /duplicate-detector/Dockerfile

WORKDIR /duplicate-detector/
RUN wget https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer -O - -q | php -- --quiet
RUN php composer.phar install
RUN rm composer.phar
RUN chmod 0777 /tmp
WORKDIR /

RUN ln -s /duplicate-detector/bin/detector /usr/local/bin/detector
