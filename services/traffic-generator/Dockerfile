FROM php:8.3-cli-alpine

WORKDIR /app
COPY generate.php ./

ENV GATEWAY_URL=http://gateway:8080 \
    REQUEST_INTERVAL_MS=3000

CMD ["php", "generate.php"]
