FROM nginx:1.27-alpine

# Install openssl (alpine nginx image doesn't ship it) and generate a
# self-signed cert at build time so HTTPS works out of the box.
RUN apk add --no-cache openssl \
    && mkdir -p /etc/nginx/certs \
    && openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -subj "/CN=localhost" \
        -keyout /etc/nginx/certs/server.key \
        -out /etc/nginx/certs/server.crt

COPY . /app/
COPY ./docker/nginx/nginx.conf /etc/nginx/conf.d/default.conf
