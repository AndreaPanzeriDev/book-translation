FROM php:8.5-cli-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libmariadb-dev \
        libsqlite3-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl zip pdo_mysql pdo_sqlite pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22, used for Vite and npm.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# L'UID/GID dell'host può coincidere con utenti/gruppi già presenti
# nell'immagine (su macOS il gid 20 è "staff" sull'host e "dialout" in Debian):
# in quel caso riusa il gruppo/utente esistente invece di far fallire il build.
RUN set -eux; \
    if getent group "${GID}" >/dev/null 2>&1; then \
        group_name="$(getent group "${GID}" | head -n 1 | cut -d: -f1)"; \
    else \
        groupadd --gid "${GID}" app; \
        group_name=app; \
    fi; \
    if ! getent passwd "${UID}" >/dev/null 2>&1; then \
        useradd --create-home --uid "${UID}" --gid "${group_name}" --shell /bin/bash app; \
    fi; \
    mkdir -p /home/app; \
    chown -R "${UID}:${GID}" /home/app

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-book-translator.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/queue-entrypoint.sh /usr/local/bin/queue-entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/queue-entrypoint.sh

WORKDIR /var/www/html

# Numerico: funziona anche quando l'UID esiste già con un altro nome.
USER ${UID}:${GID}

EXPOSE 8000 5173

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
