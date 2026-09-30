FROM php:8.2-cli-alpine AS base

COPY . /duplicate-detector/
WORKDIR /duplicate-detector/

RUN wget https://getcomposer.org/installer -O - -q | php -- --quiet; \
    php composer.phar install --no-dev --ignore-platform-req=ext-redis


FROM php:8.2-cli-alpine

# only Redis client for -r, Redis server is external
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions redis

COPY . /duplicate-detector/
COPY --from=base /duplicate-detector/vendor /duplicate-detector/vendor

RUN chmod +x /duplicate-detector/bin/detector; \
    ln -s /duplicate-detector/bin/detector /usr/local/bin/detector; \
    chmod 0777 /tmp; \
    echo "memory_limit = -1" > /usr/local/etc/php/php.ini

WORKDIR /
