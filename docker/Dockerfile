# Dev and CI image for the framework package.
#
# Deliberately much smaller than the skeleton's: a library has no assets, no web server and
# no supervisor, so there is no node, no php-fpm and no nginx here. Just php, composer and
# the extensions the suite actually touches.
#
# Declared before the first FROM so every stage can re-declare and see it.
ARG PHP_VERSION=8.4

FROM debian:trixie-slim AS base

ARG PHP_VERSION

LABEL maintainer="Gints Murans gm@gm.lv"
LABEL description="Docker image for the staticphp-core framework package"

ENV DEBIAN_FRONTEND=noninteractive

# Caches are dropped in the same layer - cleaning them later does not shrink the image
RUN apt-get update -y \
    && apt-get install -y --no-install-recommends \
        ca-certificates git wget curl unzip locales \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

# The i18n suite formats numbers and dates against real locales
RUN printf "lv_LV.UTF-8 UTF-8\nen_US.UTF-8 UTF-8\n" > /etc/locale.gen \
    && locale-gen
ENV LANG=lv_LV.UTF-8

# php from sury, matching the skeleton so a green suite here means a green suite there.
# sqlite3 is what the i18n and migration suites run against; pgsql and mysql are there so
# the driver classes can be exercised.
RUN mkdir -p /etc/apt/keyrings \
    && wget -O /etc/apt/keyrings/php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/php.gpg] https://packages.sury.org/php/ trixie main" > /etc/apt/sources.list.d/php.list \
    && apt-get update -y \
    && apt-get install -y --no-install-recommends php${PHP_VERSION}-cli \
        php${PHP_VERSION}-curl php${PHP_VERSION}-bcmath php${PHP_VERSION}-xml php${PHP_VERSION}-zip \
        php${PHP_VERSION}-mbstring php${PHP_VERSION}-intl \
        php${PHP_VERSION}-pgsql php${PHP_VERSION}-mysql php${PHP_VERSION}-sqlite3 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

RUN printf '%s\n' \
        'memory_limit = 512M' \
        'date.timezone = Europe/Riga' \
        > /etc/php/php-app.ini \
    && ln -s /etc/php/php-app.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-app.ini

# Verify the installer against the published signature before running it
RUN wget -O /tmp/composer-setup.php https://getcomposer.org/installer \
    && wget -O /tmp/composer-setup.sig https://composer.github.io/installer.sig \
    && php -r "if (hash('SHA384', file_get_contents('/tmp/composer-setup.php')) !== trim(file_get_contents('/tmp/composer-setup.sig'))) { unlink('/tmp/composer-setup.php'); echo 'Invalid installer' . PHP_EOL; exit(1); }" \
    && php /tmp/composer-setup.php --no-ansi --install-dir=/usr/local/bin --filename=composer --stable \
    && rm -f /tmp/composer-setup.*

ARG LOCAL_USER_ID=1000
ARG LOCAL_GROUP_ID=1000
RUN groupadd -g ${LOCAL_GROUP_ID} appuser \
    && useradd -u ${LOCAL_USER_ID} -g appuser -m -s /bin/bash appuser


###################
### Development ###
###################

FROM base AS development

ARG GIT_NAME
ARG GIT_EMAIL

WORKDIR /srv/app

ENV PATH="/srv/app/vendor/bin:$PATH"

RUN mkdir -p /srv/app && chown -R appuser:appuser /srv/app

USER appuser

RUN git config --global user.email "$GIT_EMAIL" \
    && git config --global user.name "$GIT_NAME" \
    && git config --global pull.rebase true \
    && git config --global safe.directory /srv/app

CMD ["bash"]


###############
### Testing ###
###############

# Everything CI runs, baked in. The source is copied rather than mounted, so this stage
# does not see working tree edits - rebuild it after changing files.
FROM development AS testing

WORKDIR /srv/app

COPY --chown=appuser:appuser . .

RUN composer install --prefer-dist --no-interaction --no-progress

CMD ["./scripts/code_tests.bash"]
