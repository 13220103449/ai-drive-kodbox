FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        apache2 \
        ca-certificates \
        curl \
        libapache2-mod-php8.2 \
        php8.2-cli \
        php8.2-curl \
        php8.2-gd \
        php8.2-intl \
        php8.2-mbstring \
        php8.2-mysql \
        php8.2-opcache \
        php8.2-sqlite3 \
        php8.2-xml \
        php8.2-zip \
        unzip; \
    a2enmod expires headers rewrite; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /etc/php/8.2/apache2/conf.d/99-ai-drive.ini
COPY docker/apache.conf /etc/apache2/conf-available/ai-drive.conf
RUN a2enconf ai-drive

COPY --chown=www-data:www-data . /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/ai-drive-entrypoint
RUN chmod 0755 /usr/local/bin/ai-drive-entrypoint \
    && rm -rf /var/www/html/data/* \
    && chown www-data:www-data /var/www/html \
    && chmod 0755 /var/www/html \
    && install -d -o www-data -g www-data -m 0770 \
        /var/www/html/data \
        /var/www/html/data/system \
        /var/www/html/data/temp

VOLUME ["/var/www/html/data"]
EXPOSE 80
STOPSIGNAL SIGWINCH

HEALTHCHECK --interval=30s --timeout=8s --start-period=60s --retries=5 \
    CMD test "$(curl -fsS http://127.0.0.1/ | wc -c)" -gt 1000 || exit 1

ENTRYPOINT ["ai-drive-entrypoint"]
CMD ["apache2", "-DFOREGROUND"]
