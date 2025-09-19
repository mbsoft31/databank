FROM php:8.3-fpm-alpine as base

# Install system dependencies
RUN apk add --no-cache \
    bash \
    git \
    curl \
    zip \
    unzip \
    nodejs \
    npm \
    icu-libs \
    libzip \
    libpq \
    oniguruma \
    shadow \
    freetype \
    libjpeg-turbo \
    libpng \
    && apk add --no-cache --virtual .build-deps \
    icu-dev \
    postgresql-dev \
    libzip-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
    intl \
    pdo_pgsql \
    bcmath \
    opcache \
    zip \
    gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Create user for Laravel
RUN addgroup -g 1000 -S www && adduser -u 1000 -S www -G www

# Development target
FROM base as development

# Copy PHP configuration
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Set user
USER www

# Start PHP-FPM
CMD ["php-fpm"]

# Production target (for future use)
FROM base as production

# Copy application files
COPY --chown=www:www . /var/www

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set user
USER www

# Start PHP-FPM
CMD ["php-fpm"]
