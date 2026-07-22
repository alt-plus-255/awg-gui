FROM php:8.4-cli-alpine

RUN apk add --no-cache docker-cli docker-cli-compose

WORKDIR /app
COPY server.php /app/server.php

EXPOSE 8090
CMD ["php", "-S", "0.0.0.0:8090", "/app/server.php"]
