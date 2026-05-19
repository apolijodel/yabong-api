FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip

RUN docker-php-ext-install mysqli pdo pdo_mysql

# REMOVE all MPM modules
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load
RUN rm -f /etc/apache2/mods-enabled/mpm_*.conf

# ENABLE ONLY prefork
RUN a2enmod mpm_prefork

COPY . /var/www/html/

EXPOSE 80