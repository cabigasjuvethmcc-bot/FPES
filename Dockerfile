# Dockerfile for FPES (PHP + Apache)
FROM php:8.2-apache

# Install PHP extensions (adjust if you need more)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache modules commonly needed
RUN a2enmod rewrite

# Set working directory inside the container
WORKDIR /var/www/html

# Copy Composer files first (for better layer caching)
COPY composer.json composer.lock ./

# Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

# Install PHP dependencies (if any) – ignore failure if there is no vendor setup
RUN composer install --no-dev --optimize-autoloader || true

# Copy the rest of the application code
COPY . .

# Environment (override in Render)
ENV APP_ENV=production

# Apache listens on port 80 inside the container
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
