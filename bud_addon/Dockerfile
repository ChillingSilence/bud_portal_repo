ARG BUILD_FROM=ghcr.io/home-assistant/base:latest
FROM $BUILD_FROM

WORKDIR /data

RUN apk -U upgrade && apk add --no-cache \
    curl \
    nginx \
    php83-fpm \
    php83-pdo \
    php83-pdo_sqlite \
    php83-session \
    php83-simplexml \
    php83-zip \
    sqlite \
    && ln -s /usr/sbin/php-fpm83 /usr/sbin/php-fpm \
    && addgroup -S php \
    && adduser -S -G php php \
    && rm -rf /var/cache/apk/* /etc/nginx/http.d/* /etc/php83/conf.d/* /etc/php83/php-fpm.d/* \
    && { \
        echo "extension=pdo.so"; \
        echo "extension=pdo_sqlite.so"; \
    } > /etc/php83/conf.d/20-pdo.ini \
    && { \
        echo "extension=session.so"; \
    } > /etc/php83/conf.d/21-session.ini \
    && { \
        echo "extension=simplexml.so"; \
        echo "extension=zip.so"; \
    } > /etc/php83/conf.d/22-s29-import.ini \
    && { \
        echo "upload_max_filesize = 64M"; \
        echo "post_max_size = 64M"; \
    } > /etc/php83/conf.d/23-uploads.ini

COPY files/general files/php83 /
RUN chown -R php:nginx /www && \
    chmod +x /etc/services.d/*/run 2>/dev/null || true && \
    chmod +x /etc/cont-init.d/* 2>/dev/null || true

ENTRYPOINT ["/init"]
