FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
      unzip git \
    && docker-php-ext-install pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY src/composer.json src/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY src/ .

EXPOSE 8080

CMD ["php", "server.php"]
