# Bumped from the legacy 1.17.8 pin: its old OpenSSL crashed (SIGILL) on modern
# TLS 1.3 handshakes. 1.27 terminates HTTPS reliably (and is itself less stale).
FROM nginx:1.27-alpine

# Generate a self-signed TLS certificate at build time (E1: HTTPS enforcement).
# Browser warning is expected — this is a teaching/self-signed cert.
RUN apk add --no-cache openssl \
    && mkdir -p /etc/nginx/certs \
    && openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout /etc/nginx/certs/nginx-selfsigned.key \
        -out /etc/nginx/certs/nginx-selfsigned.crt \
        -subj "/C=PL/ST=Local/L=Local/O=NexusOS/CN=localhost"

COPY . /app/
COPY ./docker/nginx/nginx.conf /etc/nginx/conf.d/default.conf
