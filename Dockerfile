FROM php:8.5-cli-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        libicu-dev \
        libzip-dev \
        libmariadb-dev \
        libsqlite3-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" intl zip pdo_mysql pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22, used for Vite and npm.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN groupadd --gid "${GID}" app \
    && useradd --create-home --uid "${UID}" --gid "${GID}" --shell /bin/bash app

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-book-translator.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

USER app

EXPOSE 8000 5173

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
