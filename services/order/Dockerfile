FROM php:8.3-cli-alpine

ADD --chmod=755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions opentelemetry protobuf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

ENV OTEL_PHP_AUTOLOAD_ENABLED=true \
    OTEL_TRACES_EXPORTER=otlp \
    OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf \
    OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318 \
    OTEL_PROPAGATORS=tracecontext,baggage \
    APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SECRET=demo-secret-change-in-production

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
