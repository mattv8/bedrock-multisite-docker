
## Dumping the Database

- **From Docker**
Export directly from the PHP-FPM container and write to the host's `/tmp/db_dump.sql` (paste verbatim, the containers will do the env substitutions for `$DB_USER`, etc.):

    ```bash
    sudo docker exec bedrock-php-fpm sh -c 'mysqldump -h mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"' > /tmp/db_dump.sql
    ```
-   Runs `mysqldump` inside the PHP-FPM container, using the container's env vars.
-   Redirects output to `/tmp/db_dump.sql` on the **host**.

- **From A MySQL Server**
Create your SQL dump using WP-CLI or `mysqldump` (ensure MariaDB compatibility):

    ```bash
    sudo docker exec bedrock-php-fpm sh -c 'mysqldump -h mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"' > /tmp/backup_$(date +%F).sql
    ```

## Restoring the Database (Choose an Option)

- **Option A: Running Container Restore**

    1. **Verify** your dump file is accessible on the host (e.g. `/tmp/db_dump.sql` or Windows path via WSL).

    2. **Run** this command to stream the dump into MariaDB via the PHP-FPM container (paste verbatim, the containers will do the env substitutions for `$DB_USER`, etc.):

        ```bash
        # Linux/macOS host:
        sudo docker exec -i bedrock-php-fpm sh -c 'mysql -h mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"' < /tmp/db_dump.sql

        # Windows host (WSL path translation):
        sudo docker exec -i bedrock-php-fpm sh -c 'mysql -h mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"' < "$(wslpath 'C:\Path\To\SQL.dump')"
        ```

- **Option B: Restore At Container Build**

    1. **Copy** your dump file (e.g. `backup.sql`) into your project's `mysql/` folder.

    2. If you updated `DB_NAME`, `DB_USER`, or `DB_PASSWORD` in `.env`, regenerate grants:

        ```bash
        bash .vscode/install.sh --no-themes
        ```

    3. **Recreate** the MariaDB container and volume:

        ```bash
        sudo docker compose stop mariadb
        sudo docker volume rm $(basename "$PWD")_db_data
        sudo docker compose up -d mariadb
        ```

        On startup, the container will initialize an empty database, apply grants, and import `*.sql` from `mysql/`.
