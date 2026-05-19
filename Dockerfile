FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip

RUN docker-php-ext-install mysqli pdo pdo_mysql

# Disable all MPMs first
RUN a2dismod mpm_event || true
RUN a2dismod mpm_worker || true

# Enable only prefork
RUN a2enmod mpm_prefork

COPY . /var/www/html/

EXPOSE 80