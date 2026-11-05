# Local look-around image for DocHamster. Not part of the product install
# (which uses install.php on plain hosting) – just boots the app here.
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl exif zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# D-010 minimum limits (same values CI uses).
RUN printf 'memory_limit=512M\nupload_max_filesize=32M\npost_max_size=32M\n' \
    > /usr/local/etc/php/conf.d/dochamster.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/entrypoint.sh /usr/local/bin/dochamster-entrypoint
RUN chmod +x /usr/local/bin/dochamster-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["dochamster-entrypoint"]
CMD ["apache2-foreground"]
