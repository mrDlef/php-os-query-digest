ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache git unzip

# Mutation testing needs a coverage driver. The unit suite does not, and
# compiling pcov for all seven PHP versions would slow every run for the
# benefit of one job — so it is off unless asked for.
ARG WITH_PCOV=0
RUN if [ "$WITH_PCOV" = "1" ]; then \
      apk add --no-cache --virtual .pcov-build $PHPIZE_DEPS \
      && pecl install pcov \
      && docker-php-ext-enable pcov \
      && apk del .pcov-build; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /app
