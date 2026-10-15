FROM php:8.2-apache

# Install system dependencies needed for web downloading and ZIP creation
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && docker-php-ext-install zip

# Enable Apache rewrite module if your script uses routing
RUN a2enmod rewrite

# Change Apache's default listening port to 10000 to match Render's environment
RUN sed -i 's/80/10000/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Copy your local PHP scraper files into the container
COPY . /var/www/html/

# Ensure Apache has correct permissions to write downloaded files to disk
RUN chown -W www-data:www-data /var/www/html

EXPOSE 10000
