Use PHP 8.2 with FPM
FROM php:8.2-fpm

Install system dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    git

Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

Set working directory
WORKDIR /var/www

Copy project files
COPY . .

Install Laravel dependencies
RUN composer install --optimize-autoloader --no-dev

Expose port for Laravel server
EXPOSE 8000

Start Laravel development server
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
