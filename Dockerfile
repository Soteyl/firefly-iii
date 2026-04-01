FROM fireflyiii/core:latest

USER root

COPY --chown=www-data:www-data . /var/www/html

USER www-data
