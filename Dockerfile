FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache nginx

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/app

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install dependencies first (layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application code
COPY . .
RUN composer dump-autoload --optimize --no-dev

# Ensure storage directory is writable
RUN mkdir -p /tmp/ebanx && chown -R www-data:www-data /tmp/ebanx /var/www/app

EXPOSE 8080

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

CMD ["/entrypoint.sh"]
