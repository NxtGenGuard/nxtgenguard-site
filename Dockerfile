FROM php:8.3-apache

# Enable useful Apache modules and make Apache listen on DigitalOcean App Platform's default web-service port.
RUN a2enmod rewrite headers \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

# Safe default permissions for static assets and PHP files.
RUN find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chown -R www-data:www-data /var/www/html

EXPOSE 8080
CMD ["apache2-foreground"]
