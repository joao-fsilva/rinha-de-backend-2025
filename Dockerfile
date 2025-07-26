FROM phpswoole/swoole:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/* \

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-dev --optimize-autoloader

COPY src/ .

# Expose port and define the command to run the application
EXPOSE 80
CMD ["php", "server.php"]

