FROM mariadb

# Build arguments
ARG DB_NAME
ARG DB_USER
ARG DB_PASSWORD

# Copy the original grants.sql file from your local project directory into the image
ADD ./grants.sql /docker-entrypoint-initdb.d/_grants.sql

# Replace placeholders in _grants.sql with actual values from environment variables
RUN sed -i 's/DB_NAME/'"${DB_NAME}"'/g; s/DB_USER/'"${DB_USER}"'/g; s/DB_PASSWORD/'"${DB_PASSWORD}"'/g' /docker-entrypoint-initdb.d/_grants.sql

# Expose MariaDB port
EXPOSE 3306
