FROM fireflyiii/core:latest

USER root

COPY --chown=www-data:www-data . /var/www/html

USER www-data

RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts
