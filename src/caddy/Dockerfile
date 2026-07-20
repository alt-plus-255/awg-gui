# Stage 1: Quasar SPA (alpine keeps layers small on 15GB disks)
FROM node:alpine AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install
COPY frontend/ ./
RUN npm run build

# Final: Caddy with static assets
FROM caddy:latest
COPY caddy/Caddyfile /etc/caddy/Caddyfile
COPY --from=frontend /build/dist/spa /srv
