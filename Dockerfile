FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && docker-php-ext-enable mysqli \
    && a2enmod rewrite headers \
    && a2dissite 000-default

COPY docker/apache.conf /etc/apache2/sites-available/library.conf
RUN a2ensite library

WORKDIR /var/www/library
