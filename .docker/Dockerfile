FROM php:8.3-fpm

# 1. Definição explícita de dependências de compilação e sistema
RUN apt-get update && apt-get install -y \
    $PHPIZE_DEPS \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    zip \
    unzip \
    curl \
    # Limpeza imediata para manter a imagem leve
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Configuração e Instalação de Extensões Nativas
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip opcache

# 3. Instalação do Redis (O jeito certo: PECL + Enable)
# Se o pecl falhar, o build para aqui, evitando o erro de "Class Redis not found" depois.
RUN pecl install redis && docker-php-ext-enable redis

RUN docker-php-ext-enable opcache \
    && { \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=100M'; \
        echo 'post_max_size=100M'; \
        echo 'max_execution_time=600'; \
    } > /usr/local/etc/php/conf.d/docker-php-ram-optimization.ini
# Força a criação do arquivo de configuração para garantir que o FPM carregue
# SEQUESTRA o Composer de imagem oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
EXPOSE 9000

WORKDIR /var/www

# Garante que o usuário www-data tenha permissão na pasta de trabalho
RUN chown -R www-data:www-data /var/www
USER www-data

CMD ["php-fpm"]
