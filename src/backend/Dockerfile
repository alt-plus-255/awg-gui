FROM php:cli

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl libzip-dev libpng-dev libonig-dev qrencode \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath gd \
    && rm -rf /var/lib/apt/lists/*

# Lightweight Docker CLI + compose plugin (talks to host docker.sock)
COPY --from=docker:cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/migrate-locked.sh /usr/local/bin/awg-migrate-locked
RUN chmod +x /entrypoint.sh /usr/local/bin/awg-migrate-locked

EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
