FROM php:8.2-cli-alpine

# Install required PHP extensions (pdo_mysql, zip) and MariaDB (for self-contained or external DB operation)
RUN apk add --no-cache \
    libzip-dev \
    zip \
    unzip \
    curl \
    mariadb \
    mariadb-client \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/cache/apk/*

WORKDIR /app

COPY portal /app/portal
COPY database /app/database
COPY start-railway.sh /app/start-railway.sh

RUN sed -i 's/\r$//' /app/start-railway.sh \
    && chmod +x /app/start-railway.sh \
    && mkdir -p /app/portal/app/storage/files /run/mysqld \
    && chown -R mysql:mysql /run/mysqld \
    && chmod -R 775 /app/portal/app/storage

EXPOSE 8000

CMD ["/app/start-railway.sh"]
