FROM phpswoole/swoole:6.0.1-php8.4

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

COPY composer.json composer.lock* /var/www/
COPY src/ /var/www/src/
COPY server.php /var/www/
RUN composer install --no-interaction --no-dev --optimize-autoloader

# Expose port and define the command to run the application
EXPOSE 80
CMD ["php", "server.php"]
