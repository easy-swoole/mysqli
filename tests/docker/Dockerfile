# syntax=docker/dockerfile:1
ARG PHP_IMAGE=php:8.4.27-cli-bookworm
FROM ${PHP_IMAGE}
ARG SWOOLE_VERSION=6.2.4
COPY --from=composer:2.11.1 /usr/bin/composer /usr/local/bin/composer
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS ca-certificates curl git unzip openssl libssl-dev zlib1g-dev libxml2-dev libonig-dev; \
    docker-php-ext-install -j"$(nproc)" sockets; \
    mkdir -p /usr/src/swoole; \
    curl --fail --show-error --location --retry 3 \
        "https://github.com/swoole/swoole-src/archive/refs/tags/v${SWOOLE_VERSION}.tar.gz" \
        | tar -xz --strip-components=1 -C /usr/src/swoole; \
    cd /usr/src/swoole; \
    phpize; \
    set -- --with-openssl-dir=/usr --enable-sockets --disable-brotli; \
    case "$SWOOLE_VERSION" in 5.*) set -- "$@" --enable-openssl ;; esac; \
    ./configure "$@"; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable swoole; \
    php -r 'foreach (["swoole", "openssl", "sockets", "zlib", "dom", "xml", "xmlwriter", "mbstring"] as $name) { if (!extension_loaded($name)) { throw new RuntimeException("Missing extension: " . $name); } } if (!defined("SWOOLE_SSL_TLSv1_2") || !defined("SWOOLE_SSL_TLSv1_3")) { throw new RuntimeException("Swoole TLS 1.2/1.3 support is required"); }'; \
    test "$(php -r 'echo phpversion("swoole");')" = "$SWOOLE_VERSION"; \
    rm -rf /usr/src/swoole /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer COMPOSER_CACHE_DIR=/tmp/composer-cache
WORKDIR /workspace
CMD ["bash", "/source/tests/docker/run-tests.sh"]
