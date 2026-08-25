# syntax=docker/dockerfile:1

FROM golang:1.22-bookworm AS build
WORKDIR /src
COPY go.mod ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -o /out/panel-ops ./cmd/panel-ops

FROM alpine:3.20
RUN apk add --no-cache docker-cli docker-cli-compose ca-certificates
WORKDIR /app
COPY --from=build /out/panel-ops /usr/local/bin/panel-ops
EXPOSE 8090
CMD ["panel-ops"]
