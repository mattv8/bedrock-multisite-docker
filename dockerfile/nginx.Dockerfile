FROM nginx:latest

WORKDIR /var/www

# Build arguments
ARG WP_HOME
ENV WP_HOME=${WP_HOME}
ARG SUBDOMAIN_SUFFIX
ENV SUBDOMAIN_SUFFIX=${SUBDOMAIN_SUFFIX}

# Set arguments for USER_ID and GROUP_ID (provided by docker-compose)
ARG USER_ID
ARG GROUP_ID

# Modify the www-data group and user to match the host's USER_ID and GROUP_ID
RUN groupmod -g ${GROUP_ID} www-data && \
    usermod -u ${USER_ID} -g www-data www-data

# Default log rotate interval in seconds (e.g., 3600 for hourly rotation)
ARG LOG_ROTATE_INTERVAL=86400
ENV LOG_ROTATE_INTERVAL=${LOG_ROTATE_INTERVAL}

# Default number of rotations for logs
ARG LOG_ROTATE_COUNT=14
ENV LOG_ROTATE_COUNT=${LOG_ROTATE_COUNT}

# Enable or disable compression for logs
ARG LOG_ROTATE_COMPRESS=true
ENV LOG_ROTATE_COMPRESS=${LOG_ROTATE_COMPRESS}

# Logrotate configuration template
ENV LOG_ROTATE_CONFIG_TEMPLATE="\
/var/www/log/nginx_error.log \
/var/www/log/php_errors.log \
/var/www/log/wp_debug.log \
{\n\
    missingok\n\
    rotate $LOG_ROTATE_COUNT\n\
    $(if [ \"$LOG_ROTATE_COMPRESS\" = true ]; then echo compress; fi)\n\
    $(if [ \"$LOG_ROTATE_COMPRESS\" = true ]; then echo delaycompress; fi)\n\
    notifempty\n\
    create 666 www-data www-data\n\
}"

# Save template to file
RUN eval printf \"$LOG_ROTATE_CONFIG_TEMPLATE\" | tee ~/logrotate.conf > /dev/null;

# Install logrotate
RUN apt-get update && apt-get install -y logrotate && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy the Nginx template into the expected location
COPY default.conf.template /etc/nginx/templates/default.conf.template

# Remove comments from the processed default.conf at runtime for a clean output
RUN sed -i '/^#/d' /etc/nginx/templates/default.conf.template

# Refactored CMD for better readability
CMD ["/bin/bash", "-c", "\
    export WP_HOME=$(echo \"$WP_HOME\" | sed -E 's|https?://([a-zA-Z0-9_-]+\\.)?([a-zA-Z0-9_-]+\\.[a-zA-Z]{2,}\\|localhost)|\\2|'); \
    export SUBDOMAIN_SUFFIX=\"$SUBDOMAIN_SUFFIX\"; \
    chmod 644 ~/logrotate.conf && chown --no-dereference --preserve-root 0 ~/logrotate.conf; \
    while :; do \
        echo \"Rotating logs.\"; \
        logrotate -f ~/logrotate.conf || echo \"Log rotation failed.\"; \
        sleep $LOG_ROTATE_INTERVAL; \
    done & \
    /docker-entrypoint.sh nginx -g 'daemon off;'"]
