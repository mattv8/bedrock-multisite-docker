# Restoring or Migrating an Existing WordPress Site

To migrate an existing "vanilla" WordPress site into this Docker-based Bedrock project, follow the steps below. This setup will allow you to take advantage of Bedrock’s enhanced structure and Composer-based dependency management.

### Step 1: Import Database

1. **Export the Database** from your vanilla WordPress site using  WP-CLI (or mysqldump, phpMyAdmin, but ensure it is mariadb compatible):

    ```bash
    wp db export
    ```
2. Place the SQL File in the `mysql/` directory in your Bedrock Docker project. This setup will automatically import any .sql files in `mysql/` when the MySQL Docker container is built.

3. Rebuild the MySQL Container:

    ```bash
    sudo docker compose build mariadb
    ```
This will restore your database to the Bedrock project.

### Step 2: Migrate Themes
1. Copy your themes from the vanilla site to the Bedrock directory. Bedrock uses `web/app/` instead of `wp-content/`, so copy themes accordingly:

    ```bash
    cp -R /path/to/vanillawp/wp-content/themes/* ./web/app/themes
    ```
### Step 3: Migrate Plugins
Instead of copying plugins directly, Bedrock manages them through Composer, allowing for better version control and dependency management.

1. List Installed Plugins from the vanilla site using WP-CLI:

    ```bash
    wp plugin list
    ```

    Install Free Plugins via Composer; free plugins are available on WP Packagist. You can install them with:

    ```bash
    composer require wpackagist-plugin/{plugin-name}
    ```

    For multiple plugins, list them together:

    ```bash
    composer require wpackagist-plugin/advanced-custom-fields wpackagist-plugin/akismet
    ```

2. Install Premium Plugins:

    Some premium plugins may support Composer directly. If not, consider using tools like Satispress to manage them in a private repository.

### Step 4: Migrate Uploads
Copy the uploads directory to web/app/uploads/ to match Bedrock’s structure:

```bash
rsync -vrz /path/to/vanillawp/wp-content/uploads/ ./web/app/uploads/
```

### Step 5: Update Database References
After importing the database, update paths and URLs to match Bedrock’s structure and local setup.

1. Run Search and Replace commands via WP-CLI within the Docker container:

    ```bash
    sudo docker compose exec php-fpm wp search-replace "http://vanilla-site-url" "http://bedrock-site-url"
    ```
    ```bash
    sudo docker compose exec php-fpm wp search-replace "/wp-content/" "/app/"
    ```
    ```bash
    sudo docker compose exec php-fpm wp option update home "http://bedrock-site-url"
    ```
    ```bash
    sudo docker compose exec php-fpm wp option update siteurl "http://bedrock-site-url"
    ```
2. Verify Additional Replacements:

    If you use page builders or custom setups, ensure additional references are updated.

For a more in-depth guide, see [How to Convert Vanilla WP Site to Bedrock](https://neonbrand.com/websites/wordpress/how-to-convert-vanilla-wp-site-to-bedrock/) by Neonbrand.

Your migrated site should now be live and fully integrated into the Bedrock structure, ready for development within this Docker setup.