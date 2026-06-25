FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recursive \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json /app/composer.json
RUN composer install --no-interaction --no-progress --prefer-dist || true

COPY . /app

RUN composer install --no-interaction --no-progress --prefer-dist

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "public/router.php"]
