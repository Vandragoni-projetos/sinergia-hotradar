# SINERGIA HOTRADAR — imagem de produção (paridade com o padrão Achadinhos: Apache + PHP 8.2)
# NÃO compartilha nada com o Achadinhos: banco, credenciais e deploy são próprios.
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html
RUN rm -rf /var/www/html/.tools /var/www/html/.git \
    && mkdir -p storage/logs storage/collect \
    && chown -R www-data:www-data /var/www/html/storage

# HR_DB_DRIVER=mysql em produção (EasyPanel Environment). MariaDB próprio do HOTRADAR.
EXPOSE 80
