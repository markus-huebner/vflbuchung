FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
COPY app ./app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.2-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mbstring mysqli pdo_mysql \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

WORKDIR /var/www/app
COPY app ./app
COPY www ./www
COPY database ./database
COPY --from=dependencies /app/vendor ./vendor
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
