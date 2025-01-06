FROM php:8.3-fpm

# Update sources list to use a faster mirror if /etc/apt/sources.list exists
# RUN sed -i 's|http://deb.debian.org/debian|http://mirrors.ocf.berkeley.edu/debian|g' /etc/apt/sources.list.d/debian.sources

# Set arguments for UID and GID (provided by docker-compose)
ARG UID
ARG GID

# Install dependencies
RUN apt update -o Acquire::http::Timeout="60"

RUN apt install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    less \
    mariadb-client \
    sudo \
    curl && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Modify the www-data group and user to match the host's UID and GID
RUN groupmod -g ${GID} www-data && \
    usermod -u ${UID} -g www-data www-data

# Set the working directory and ownership
WORKDIR /var/www
RUN chown -R www-data:www-data /var/www

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql mbstring exif pcntl bcmath zip mysqli

# Enable PHP extensions
RUN docker-php-ext-enable gd pdo pdo_mysql mbstring exif pcntl bcmath zip mysqli

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# Verify WP-CLI installation
RUN wp --info

# Install Composer
RUN curl -sS https://getcomposer.org/installer -o composer-setup.php \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

# Verify Composer installation
RUN composer --version

# Clean up
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Run as www-data
USER www-data
