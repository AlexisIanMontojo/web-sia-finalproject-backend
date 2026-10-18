
ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-apache

# Install system packages needed by Composer
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip pdo pdo_mysql mysqli \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Set LavaLust's public folder as the web root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Configure Apache to serve the public directory
RUN sed -ri \
    's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf \
    && printf '\n<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' >> /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies, including PHPMailer
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

# Copy LavaLust application
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
