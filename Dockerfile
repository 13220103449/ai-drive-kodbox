FROM php:8.2-apache-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        curl \
        exif \
        gd \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        pdo_sqlite \
        xml \
        zip; \
    a2enmod expires headers rewrite; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/ai-drive.ini
COPY docker/apache.conf /etc/apache2/conf-available/ai-drive.conf
RUN a2enconf ai-drive

COPY --chown=www-data:www-data . /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/ai-drive-entrypoint
RUN chmod 0755 /usr/local/bin/ai-drive-entrypoint \
    && rm -rf /var/www/html/data/* \
    && install -d -o www-data -g www-data -m 0770 \
        /var/www/html/data \
        /var/www/html/data/system \
        /var/www/html/data/temp

VOLUME ["/var/www/html/data"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=8s --start-period=60s --retries=5 \
    CMD test "$(curl -fsS http://127.0.0.1/ | wc -c)" -gt 1000 || exit 1

ENTRYPOINT ["ai-drive-entrypoint"]
CMD ["apache2-foreground"]
