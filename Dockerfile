ARG PHP_VERSION=7.4-cli
FROM composer:2.4.4 AS composer

FROM php:${PHP_VERSION}

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN pecl install xdebug-3.1.6 &&  \
    docker-php-ext-enable xdebug &&  \
    echo "xdebug.mode=debug" >> "$PHP_INI_DIR"/conf.d/xdebug.ini
