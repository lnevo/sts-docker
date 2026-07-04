#!/bin/bash

# Check if the database should be provisioned
if [ "$PROVISION_DATABASE" = "1" ]; then
    # Check if the database has been initialized
    if [ ! -f /opt/sql.initialized ]; then

        # STS system default: schema, settings, and empty table shells
        if mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /var/www/html/sts/create_sts_db3.sql; then
            # HART layout overlay (same file reseed copies into the container)
            if [ -f /var/www/html/sts/seed_hart_data.sql ]; then
                if ! mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /var/www/html/sts/seed_hart_data.sql; then
                    echo "MySQL HART seed command failed. Database initialization aborted."
                    exit 1
                fi
            fi
            # Create a marker to indicate that database initialization is completed
            touch /opt/sql.initialized
        else
            echo "MySQL command failed. Database initialization aborted."
        fi

    fi
fi

# Start Apache
exec apache2-foreground
