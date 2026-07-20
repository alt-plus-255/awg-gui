# Build with current Go toolchain (amneziawg-go needs a recent Go)
FROM golang:alpine AS builder

RUN apk add --no-cache \
    bash \
    git \
    make \
    build-base \
    linux-headers \
    curl \
    tar

WORKDIR /tmp/build

RUN git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-go.git \
 && cd amneziawg-go \
 && make \
 && install -m 0755 amneziawg-go /usr/local/bin/amneziawg-go

RUN git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-tools.git \
 && cd amneziawg-tools/src \
 && make \
 && make install

# Prefer vendored tarball to avoid flaky GitHub TLS during image build.
ARG SING_BOX_VERSION=1.12.12
COPY vendor/sing-box-${SING_BOX_VERSION}-linux-*.tar.gz /tmp/sing-box.tgz
RUN ARCH="$(uname -m)" \
 && case "${ARCH}" in \
      x86_64) SB_ARCH=amd64 ;; \
      aarch64) SB_ARCH=arm64 ;; \
      armv7l) SB_ARCH=armv7 ;; \
      *) echo "unsupported arch: ${ARCH}" >&2; exit 1 ;; \
    esac \
 && tar -xz -C /tmp -f /tmp/sing-box.tgz \
 && install -m 0755 "/tmp/sing-box-${SING_BOX_VERSION}-linux-${SB_ARCH}/sing-box" /usr/local/bin/sing-box \
 && rm -f /tmp/sing-box.tgz

FROM alpine:latest

RUN apk add --no-cache \
    bash \
    curl \
    iptables \
    ip6tables \
    iproute2 \
    openresolv \
    procps \
    bind-tools

COPY --from=builder /usr/local/bin/amneziawg-go /usr/bin/amneziawg-go
COPY --from=builder /usr/bin/awg /usr/bin/awg
COPY --from=builder /usr/bin/awg-quick /usr/bin/awg-quick
COPY --from=builder /usr/local/bin/sing-box /usr/local/bin/sing-box

RUN ln -sf /usr/bin/awg /usr/bin/wg \
 && ln -sf /usr/bin/awg-quick /usr/bin/wg-quick

COPY entrypoint.sh /entrypoint.sh
COPY reload-singbox.sh /usr/local/bin/reload-singbox.sh
COPY reload-singbox-ping.sh /usr/local/bin/reload-singbox-ping.sh
RUN chmod +x /entrypoint.sh /usr/local/bin/reload-singbox.sh /usr/local/bin/reload-singbox-ping.sh

VOLUME ["/config"]
ENTRYPOINT ["/entrypoint.sh"]
