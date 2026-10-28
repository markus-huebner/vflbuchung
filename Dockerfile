FROM composer:2 AS dependencies
WORKDIR /app
COPY www/composer.json www/composer.lock ./
COPY www/app ./app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.2-apache
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends libonig-dev msmtp \
    && docker-php-ext-install mbstring mysqli pdo_mysql \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

WORKDIR /var/www/app
COPY www ./www
COPY --from=dependencies /app/vendor ./www/vendor
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN mkdir -p /var/www/app/www/storage /var/www/app/www/config \
    && printf 'Require all denied\n' > /var/www/app/www/storage/.htaccess \
    && printf 'Require all denied\n' > /var/www/app/www/vendor/.htaccess \
    && chown www-data:www-data /var/www/app/www/config \
    && chmod 0700 /var/www/app/www/config \
    && chown www-data:www-data /var/www/app/www/storage \
    && chmod 0700 /var/www/app/www/storage
