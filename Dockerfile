FROM php:8.5-apache-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libpq-dev unzip git \
    && docker-php-ext-install -j2 intl mbstring pdo_pgsql pcntl bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
EXPOSE 10000
ENTRYPOINT ["bash", "/var/www/html/deploy/start.sh"]
CMD ["web"]
