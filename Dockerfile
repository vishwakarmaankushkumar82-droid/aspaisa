FROM php:8.1-apache-bullseye

# Install required extensions
RUN apt-get update -y && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install sqlite3 \
    && rm -rf /var/lib/apt/lists/*


# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy application files
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data

# Create SQLite database directory
RUN mkdir -p /var/www/html/db && chmod 755 /var/www/html/db

# Health check
HEALTHCHECK --interval=30s --timeout=3s \

  CMD curl -f http://localhost/ || exit 1








