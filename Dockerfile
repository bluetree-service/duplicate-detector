FROM php:7.4-cli-alpine AS base

COPY . /duplicate-detector/
WORKDIR /duplicate-detector/

RUN wget https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer -O - -q | php -- --quiet; \
    php composer.phar install --no-dev; \
    php composer.phar global require mediamonks/composer-vendor-cleaner; \
    du -sh /duplicate-detector/vendor; \
    php /root/.composer/vendor/mediamonks/composer-vendor-cleaner/bin/clean --dir /duplicate-detector/vendor/; \
    du -sh /duplicate-detector/vendor


FROM php:7.4-cli-alpine

COPY . /duplicate-detector/
COPY --from=base /duplicate-detector/vendor /duplicate-detector/

RUN ln -s /duplicate-detector/bin/detector /usr/local/bin/detector; \
    chmod 0777 /tmp

WORKDIR /
