FROM php:8.4-cli-alpine

WORKDIR /app

RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libpq-dev \
    linux-headers \
    nodejs \
    npm \
    oniguruma-dev \
    postgresql-client \
    unzip \
    zip \
    && docker-php-ext-install intl mbstring pcntl pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/backend/entrypoint.sh /usr/local/bin/backend-entrypoint.sh
RUN chmod +x /usr/local/bin/backend-entrypoint.sh

EXPOSE 8000 5173

ENTRYPOINT ["/usr/local/bin/backend-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
