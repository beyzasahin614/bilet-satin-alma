FROM php:8.2-apache

RUN apt-get update && apt-get install -y libsqlite3-dev

# PHP eklentilerini kur
RUN docker-php-ext-install pdo pdo_sqlite

#.htaccess desteği
RUN a2enmod rewrite

WORKDIR /var/www/html
COPY . .

EXPOSE 80

CMD ["apache2-foreground"]
