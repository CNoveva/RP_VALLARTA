# Tapanko Vallarta - PHP 8.2 + Apache para Coolify
FROM php:8.2-apache

# Zona horaria del contenedor: los turnos (5:00 pm a 4:59 pm) se calculan con date() de PHP
ENV TZ=America/Mexico_City
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Extensiones: PDO MySQL (la app usa PDO)
RUN docker-php-ext-install pdo_mysql

# Configuración de PHP y Apache
COPY docker/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/apache.conf /etc/apache2/conf-available/app.conf
RUN a2enconf app && a2enmod headers

# Código de la aplicación
COPY --chown=www-data:www-data . /var/www/html/

# El cron de inasistencias se ejecuta desde Coolify (Scheduled Tasks):
#   php /var/www/html/scripts/verificar_inasistencias.php
RUN chmod +x /var/www/html/scripts/verificar_inasistencias.php

EXPOSE 80
