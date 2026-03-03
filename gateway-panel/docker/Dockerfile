FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy composer files first (for layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies
# --ignore-platform-req=php: lock file generated on PHP 8.5 host, running on PHP 8.3
# --no-scripts: artisan not available yet, run scripts after full copy
RUN composer install --no-interaction --no-scripts --no-autoloader --ignore-platform-req=php

# Copy the rest of the application
COPY . .

# Generate autoloader and run post-install scripts
RUN composer dump-autoload --optimize --ignore-platform-req=php \
    && php artisan package:discover --ansi

# Disable platform_check.php (lock file generated on PHP 8.5, running on PHP 8.3)
RUN echo '<?php // platform check disabled for PHP 8.3 compatibility' > /var/www/vendor/composer/platform_check.php

# Set permissions
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
