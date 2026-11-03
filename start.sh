#!/bin/bash

# Check if the database should be provisioned
if [ "$PROVISION_DATABASE" = "1" ]; then
    # Check if the database has been initialized
    if [ ! -f /opt/sql.initialized ]; then

        # Execute the SQL script on the newly created database with authentication
        if mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /var/www/html/sts/create_sts_db3.sql; then
            # Create a marker to indicate that database initialization is completed
            touch /opt/sql.initialized
        else
            echo "MySQL command failed. Database initialization aborted."
        fi

    fi
fi

# API admin token bootstrap: writes /var/lib/sts/bootstrap_admin.token when no
# active admin token exists. Runs as www-data so the token store stays readable
# by Apache. Never blocks startup.
mkdir -p /var/lib/sts
chown www-data:www-data /var/lib/sts 2>/dev/null || true
runuser -u www-data -- php /var/www/html/sts/api/bin/tokens.php bootstrap \
    || echo "API token bootstrap failed (continuing)"

# Start Apache
exec apache2-foreground
