FROM phpswoole/swoole:php8.2-cli

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Debian/Ubuntu 的包管理，安装 Caddy 官方源，并添加 PostgreSQL 客户端及扩展支持
RUN apt-get update && apt-get install -y curl apt-transport-https debian-keyring debian-archive-keyring && \
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg && \
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list && \
    apt-get update && \
    apt-get install -y shadow sqlite3 postgresql-client git patch supervisor redis-tools caddy && \
    rm -rf /var/lib/apt/lists/* && \
    CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip redis pdo_pgsql pgsql && \
    groupadd -g 1000 www && useradd -u 1000 -g www -s /bin/bash -m www && \
    (getent group redis || groupadd redis) && \
    (getent passwd redis || useradd -g redis -d /data -s /bin/false redis)

WORKDIR /www

COPY .docker /

# 将原Xboard仓库地址指向自己的 GitHub仓库
ARG CACHEBUST=1
ARG REPO_URL=https://github.com/qukuan/xboard
ARG BRANCH_NAME=master

RUN echo "Attempting to clone branch: ${BRANCH_NAME} from ${REPO_URL} with CACHEBUST: ${CACHEBUST}" && \
    rm -rf ./* && \
    rm -rf .git && \
    git config --global --add safe.directory /www && \
    git clone --depth 1 --branch ${BRANCH_NAME} ${REPO_URL} . && \
    git submodule update --init --recursive --force

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN composer install --no-cache --no-dev --no-security-blocking \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]