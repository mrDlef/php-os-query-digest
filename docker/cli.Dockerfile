# The distributable image: the CLI, and nothing else.
#
# `docker/Dockerfile` beside this one is the development image — Composer, git,
# a vendor tree per PHP version — and it is not this. This one exists for the
# people who have the slow log and no PHP toolchain: SREs, and pipelines.
#
#   docker build -f docker/cli.Dockerfile -t os-query-digest .
#   cat slowlog | docker run -i --rm os-query-digest slowlog
#
# The phar is built here rather than copied in, so the image is reproducible
# from a checkout and cannot ship an artefact nobody rebuilt.

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine AS build

# Only what the phar carries. Not tests, not vendor, not the docs: a build
# context that changed the image every time a page was edited would invalidate
# the layer cache for nothing.
WORKDIR /src
COPY src/ src/
COPY bin/os-query-digest bin/os-query-digest
COPY tools/build-phar.php tools/build-phar.php

# Stamped into the phar, and reported by `--version`. The build has no git
# history to describe itself from.
ARG VERSION=dev
ENV OS_QUERY_DIGEST_VERSION=${VERSION}

RUN php -d phar.readonly=0 tools/build-phar.php /out/os-query-digest.phar

FROM php:${PHP_VERSION}-cli-alpine

ARG VERSION=dev

LABEL org.opencontainers.image.title="os-query-digest" \
      org.opencontainers.image.description="Read OpenSearch queries, group them, find the slow ones." \
      org.opencontainers.image.source="https://github.com/mrDlef/php-os-query-digest" \
      org.opencontainers.image.documentation="https://mrdlef.github.io/php-os-query-digest/" \
      org.opencontainers.image.licenses="LGPL-3.0-or-later" \
      org.opencontainers.image.version="${VERSION}"

COPY --from=build /out/os-query-digest.phar /usr/local/bin/os-query-digest

# Nothing is written and nothing is served, so the tool needs no account of its
# own. A mounted log is read with the caller's identity instead:
#   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/logs" … slowlog /logs/x
USER nobody

ENTRYPOINT ["os-query-digest"]

# So that `docker run <image>` with no arguments is useful rather than a usage
# error — the first thing anyone does with an unfamiliar image.
CMD ["--help"]
