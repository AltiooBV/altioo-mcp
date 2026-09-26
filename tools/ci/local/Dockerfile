# The runner that stands in for a GitHub Actions ubuntu-latest job.
#
# It exists so that the matrix can be answered without spending Actions
# minutes, and so that a failure that only happens on one PHP version can be
# reproduced on a machine whose own PHP is a third one. Everything the
# workflows get from `setup-php` and from the runner image is assembled here,
# and nothing else is: this is not a development environment.
#
#   docker build --build-arg PHP_VERSION=8.2 -t altioo-ci:php8.2 tools/ci/local
#
# tools/ci/local/run.sh builds it for you; this file is here to be read and
# edited when a version stops installing.
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli

# The extension list is the one both workflows pass to setup-php, plus what the
# setup itself needs from the machine:
#
#   graphviz              the impact-analysis graphs. The setup warns without
#                         it, and the response file names /usr/bin/dot.
#   default-mysql-client  CheckBackupPrerequisites shells out to mysqldump and
#                         refuses the whole install when it is missing. GitHub's
#                         ubuntu-latest carries the client already, which is why
#                         no workflow step installs it and why this line has no
#                         counterpart in itop-matrix.yml.
#   rsync                 install-itop.sh copies the module with it.
#   dpkg-dev              only for dpkg-architecture below.
#
# mbstring and xml are compiled into the official images; asking for them here
# would fail the build rather than be a no-op.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libxml2-dev libpng-dev libjpeg-dev libfreetype6-dev \
      libldap2-dev libsasl2-dev zlib1g-dev libonig-dev \
      graphviz unzip zip rsync curl git ca-certificates procps \
      default-mysql-client dpkg-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)" \
 && docker-php-ext-install -j"$(nproc)" gd intl mysqli soap zip ldap opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# memory_limit matches the ini-values both workflows set. phpcs is fetched as a
# phar for the same reason ci.yml installs it through setup-php rather than
# through Composer: it is build tooling, and a linter in composer.lock is a
# package an operator can see in the bill of materials but never runs.
#
# Pinned to a version and a digest rather than /releases/latest: a phar is
# downloaded and then executed with no interpreter to sandbox it, so "whatever
# is newest today" was both a moving target and an unverified one. Bumping the
# version here is now a reviewable diff instead of a silent change the next
# build happens to pick up.
ARG PHPCS_VERSION=4.0.4
ARG PHPCS_SHA256=4b010cd21d8bc8a17e2504792e3c77ef8259126f24caf7eafe7eddef1fa871e9
RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/ci.ini \
 && curl -fsSL -o /usr/local/bin/phpcs \
      "https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/download/${PHPCS_VERSION}/phpcs.phar" \
 && echo "${PHPCS_SHA256}  /usr/local/bin/phpcs" | sha256sum -c - \
 && chmod +x /usr/local/bin/phpcs

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/work/.composer
WORKDIR /work
