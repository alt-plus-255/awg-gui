FROM php:8.4-cli-alpine

RUN apk add --no-cache docker-cli docker-cli-compose

WORKDIR /app
COPY server.php /app/server.php
<<<<<<< HEAD
=======
COPY update-runner.php /app/update-runner.php
COPY awg-kernel-runner.php /app/awg-kernel-runner.php
>>>>>>> a34ec4d81547d4963b761827020a578f3957b1c6

EXPOSE 8090
CMD ["php", "-S", "0.0.0.0:8090", "/app/server.php"]
