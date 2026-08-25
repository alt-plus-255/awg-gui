# syntax=docker/dockerfile:1

FROM golang:1.22-bookworm AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -o /out/awggui ./cmd/awggui \
 && CGO_ENABLED=0 GOOS=linux go build -o /out/awgctl ./cmd/awgctl

FROM debian:bookworm-slim
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl qrencode util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd -g 33 -o app \
    && useradd -u 33 -g 33 -o -M -d /app -s /usr/sbin/nologin app \
    && mkdir -p /var/lib/awggui /var/lock \
    && chmod 1777 /var/lock \
    && chown -R 33:33 /var/lib/awggui

COPY --from=docker:cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

WORKDIR /app
COPY --from=build /out/awggui /usr/local/bin/awggui
COPY --from=build /out/awgctl /usr/local/bin/awgctl
COPY migrations /app/migrations
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/migrate-locked.sh /usr/local/bin/awg-migrate-locked
RUN chmod +x /entrypoint.sh /usr/local/bin/awg-migrate-locked /usr/local/bin/awggui /usr/local/bin/awgctl \
    && chown -R 33:33 /app

ENV MIGRATIONS_DIR=/app/migrations
EXPOSE 8000 8081
USER 33:33
ENTRYPOINT ["/entrypoint.sh"]
CMD ["awggui"]
