# Test-panel image: stock cedar2025 image + current local working-tree code.
# Local commits are unpushed, so the upstream build-time git clone cannot see
# them; overlay the code dirs instead. Vendor has no drift (composer files
# identical to origin/master), so the image vendor stays.
FROM ghcr.io/cedar2025/xboard:latest
WORKDIR /www
COPY app /www/app
COPY config /www/config
COPY bootstrap /www/bootstrap
# 宿主机的 package manifest（bootstrap/cache/*.php）由 composer dev-install 生成，
# 引用 require-dev 包；镜像 vendor 是 --no-dev，留着会启动即炸。删掉让 Laravel
# 在容器内按实际 vendor 重建 manifest。
RUN rm -f /www/bootstrap/cache/packages.php /www/bootstrap/cache/services.php
COPY routes /www/routes
COPY database /www/database
COPY resources /www/resources
COPY plugins-core /www/plugins-core
COPY artisan /www/artisan
COPY composer.json /www/composer.json
