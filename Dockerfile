FROM php:8.5-fpm-alpine
RUN apk add --no-cache mariadb-client \
 && docker-php-ext-install pdo_mysql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN chown -R www-data:www-data storage bootstrap/cache
CMD ["php-fpm", "-F"]
