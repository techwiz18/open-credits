FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev unzip \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql zip opcache \
  && a2enmod rewrite \
  && rm -rf /var/lib/apt/lists/*

# XenForo needs upload limits + opcache
RUN { \
    echo 'upload_max_filesize=64M'; \
    echo 'post_max_size=64M'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=120'; \
  } > /usr/local/etc/php/conf.d/xenforo.ini

WORKDIR /var/www/html
