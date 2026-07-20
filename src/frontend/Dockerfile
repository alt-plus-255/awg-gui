FROM node:alpine AS build
WORKDIR /build
COPY package.json package-lock.json* ./
RUN npm install
COPY . .
RUN npm run build

FROM alpine:latest
COPY --from=build /build/dist/spa /out
# Used only as build context dependency for caddy Dockerfile
