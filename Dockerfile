#checkov:skip=CKV_DOCKER_3: not using a default user.
# Use php:8.4.27-apache-bookworm as base image
# 8.4.27 is the first 8.4 release patched against PHP2Shell (CVE-2026-109618 / CVE-2026-109619).
# Do not drop below it.
FROM php:8.4.27-apache-bookworm

# Expose port 80
EXPOSE 80

# Update sources and install all dependencies in a single RUN statement to reduce layers and image size
# hadolint ignore=DL3008
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    mariadb-client \
    zlib1g-dev \
    libpng-dev && \
    docker-php-ext-configure gd && \
    docker-php-ext-install -j"$(nproc)" gd mysqli && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# PHP settings:
# - session.upload_progress.enabled=0 is the PHP2Shell interim mitigation for CVE-2026-109619,
#   kept as defence in depth (STS doesn't use upload progress).
# - E_DEPRECATED is suppressed so 8.1+ deprecation notices from legacy code (incl. phpqrcode /
#   php-barcode) don't print into pages or corrupt generated images.
RUN printf '%s\n' \
    'session.upload_progress.enabled = 0' \
    'error_reporting = E_ALL & ~E_DEPRECATED' \
    > "$PHP_INI_DIR/conf.d/zz-sts.ini"

# Add additional folders
COPY php-barcode /var/www/html/php-barcode
COPY phpqrcode /var/www/html/phpqrcode
COPY sts /var/www/html/sts

# Make all copied files readable by Apache (www-data)
RUN find /var/www/html -type f -exec chmod 644 {} \; && \
    find /var/www/html -type d -exec chmod 755 {} \;

# Edit permissions for directories and create folder structure
RUN mkdir -p /var/www/html/sts/temp \
    /var/www/html/sts/ImageStore/DB_Images/barcodes \
    /var/www/html/sts/ImageStore/DB_Images/qrcodes \
    /var/www/html/sts/ImageStore/DB_Images/RollingStock \
    /var/www/html/sts/ImageStore/DB_Images/uploads && \
    chown -R www-data:www-data \
    /var/www/html/sts/backups \
    /var/www/html/sts/temp \
    /var/www/html/sts/uploads \
    /var/www/html/sts/ImageStore \
    /var/www/html/sts/cargo_list.txt && \
    chmod -R u=rwX,g=rX,o=rX \
    /var/www/html/sts/backups \
    /var/www/html/sts/temp \
    /var/www/html/sts/uploads \
    /var/www/html/sts/ImageStore \
    /var/www/html/sts/cargo_list.txt

# Copy start script
COPY start.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/start.sh

# Health check using curl
HEALTHCHECK --interval=30s --timeout=10s \
  CMD curl --silent --fail http://localhost/sts || exit 1

# Define entrypoint
ENTRYPOINT ["/usr/local/bin/start.sh"]
