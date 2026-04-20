FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        fonts-noto-cjk \
        python3 \
        python3-pil \
        python3-qrcode \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/inventory.ini
COPY . /var/www/html/
COPY config.docker.php /var/www/html/config.php

RUN chown -R www-data:www-data /var/www/html
