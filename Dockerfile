FROM php:8.3-fpm

# Instalación de dependencias del sistema y extensiones PHP necesarias
RUN apt-get update && apt-get install -y \
    git unzip zip curl libicu-dev libonig-dev libzip-dev libpng-dev libxml2-dev \
    libmagickwand-dev --no-install-recommends \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install intl mbstring pdo pdo_mysql zip opcache gd xml

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instalar Node.js (versión LTS actual) y Yarn
RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g yarn

# Definir directorio de trabajo
WORKDIR /var/www/html

# Ajustar permisos para que coincidan con el host
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

EXPOSE 9000
CMD ["php-fpm"]

