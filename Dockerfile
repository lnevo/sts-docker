#checkov:skip=CKV_DOCKER_3: not using a default user.
# Use php:8.0-apache-buster as base image
FROM php:8.0-apache-buster

# Expose port 80
EXPOSE 80

# Update sources and install all dependencies in a single RUN statement to reduce layers and image size
RUN sed -i 's|http://deb.debian.org/debian|http://archive.debian.org/debian|g' /etc/apt/sources.list && \
    sed -i '/security.debian.org/d' /etc/apt/sources.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
    mariadb-client \
    zlib1g-dev \
    libpng-dev && \
    docker-php-ext-configure gd && \
    docker-php-ext-install -j"$(nproc)" gd mysqli && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

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
    /var/www/html/sts/ImageStore/DB_Images/RollingStock && \
    chmod -R 757 /var/www/html/sts/backups \
    /var/www/html/sts/ImageStore \
    /var/www/html/sts/temp \
    /var/www/html/sts/uploads && \
    chmod 757 /var/www/html/sts/cargo_list.txt && \
    chown -R www-data:www-data \
    /var/www/html/sts/backups \
    /var/www/html/sts/temp \
    /var/www/html/sts/uploads

# Copy start script
COPY start.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/start.sh && \
    chmod +x /var/www/html/sts/load_hart_seed.sh

# Health check using curl
HEALTHCHECK --interval=30s --timeout=10s \
  CMD curl --silent --fail http://localhost/sts || exit 1

# Define entrypoint
ENTRYPOINT ["/usr/local/bin/start.sh"]
