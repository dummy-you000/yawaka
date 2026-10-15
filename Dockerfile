FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libxml2-dev \
    && docker-php-ext-install curl dom zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

CMD ["bash", "-lc", "PORT=${PORT:-10000}; sed -i \"s/^Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf; sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf; apache2-foreground"]
