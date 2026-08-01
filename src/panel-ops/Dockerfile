FROM php:8.4-cli-alpine

RUN apk add --no-cache docker-cli docker-cli-compose

WORKDIR /app
COPY server.php /app/server.php
COPY update-runner.php /app/update-runner.php
COPY awg-kernel-runner.php /app/awg-kernel-runner.php

EXPOSE 8090
CMD ["php", "-S", "0.0.0.0:8090", "/app/server.php"]
