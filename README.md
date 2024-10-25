# Bedrock Docker Multisite Development Stack

A Docker-based development environment for Bedrock WordPress multisite, designed to streamline local development, version control, and CI/CD integration. This setup is optimized for developers working in a multisite environment and makes it easy to manage WordPress installations in a consistent, containerized environment.

> **Note:** This project is an independent setup and is not officially supported by [Roots/Bedrock](https://roots.io/bedrock/).

## What is Bedrock?

[Bedrock](https://roots.io/bedrock/) is a modern WordPress stack that enhances WordPress development with improved file organization, dependency management through Composer, environment-specific configurations, and security improvements. Bedrock also simplifies configuration for development, staging, and production environments by leveraging `.env` files to store environment-specific variables.

This setup uses Docker to containerize WordPress, making it easier to manage dependencies and reproduce environments reliably across development teams. With Docker and Docker Compose, the entire stack is preconfigured and ready to use.

## Project Benefits

This Docker-based Bedrock WordPress stack provides:

- **Isolated Development Environment**: Containerized setup for WordPress, PHP, MySQL, and Nginx, making the development environment consistent and isolated.
- **Multisite Ready**: Out-of-the-box support for WordPress multisite, perfect for projects that require a network of sites.
- **Automatic Dependency Management**: Use Composer to manage WordPress core, plugins, and theme dependencies.
- **Environment-Specific Configuration**: `.env` files to easily switch between development, staging, and production configurations.
- **Version Control and CI/CD Friendly**: Keeps WordPress core and dependencies outside of the main project root, making it easy to track project files in version control.

## Prerequisites

> **Note For Windows Users**: This project is designed to run on Linux, but can run on Windows using [Windows Subsystem for Linux (WSL)](https://docs.microsoft.com/en-us/windows/wsl/). Make sure WSL 2 is enabled and configured and that you have a basic understanding of Linux.

- **Docker and Docker Compose**: Ensure Docker (including Docker Compose v2) is installed. Most Docker installations include Docker Compose v2 by default. For installation instructions, refer to the [Docker documentation](https://docs.docker.com/get-docker/) and the [Docker Compose guide](https://docs.docker.com/compose/install/).

- **PHP (Required for Composer)**: Composer requires PHP to be installed on your system. Make sure PHP is available globally. For installation instructions, refer to [PHP.net](https://www.php.net/manual/en/install.php).

- **Composer**: Composer is required to manage PHP dependencies in this project. Follow the [official Composer installation guide](https://getcomposer.org/download/) to install it globally.

    **Quick Installation for Unix-based systems**:
    ```bash
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    php -r "unlink('composer-setup.php');"
    ```

## Installation

### 1. Set Up the Project with Composer

Install the project using `composer create-project`:

```bash
composer create-project mattv8/bedrock-multisite-docker your-project-directory
cd your-project-directory
```
> **Note:** The composer install script will automatically set up the necessary files in the root directory and ensure any existing .env files are preserved.

### 2. Configure the Environment Variables
Copy the `.env.example` file to .env and configure the variables as needed:

```bash
cp .env.example .env
```

### 3. Build and Start the Docker Containers
Launch the Docker containers with Docker Compose:

```bash
sudo sudo docker compose up --build
```
> **Note:** the `--build` flag is only necessary on first run.

### 4. Access the Site
After the containers have started, you can access your WordPress site at:

```plaintext
http://localhost:81
```

## Restoring or Migrating an Existing WordPress Site

To migrate an existing "vanilla" WordPress site into this Docker-based Bedrock project, follow the steps below. This setup will allow you to take advantage of Bedrock’s enhanced structure and Composer-based dependency management.

#### Step 1: Import Database

1. **Export the Database** from your vanilla WordPress site using  WP-CLI (or mysqldump, phpMyAdmin, but ensure it is mariadb compatible):

    ```bash
    wp db export
    ```
2. Place the SQL File in the `./mysql/` directory in your Bedrock Docker project. This setup will automatically import any .sql files in ./mysql/ when the MySQL Docker container is built.

3. Rebuild the MySQL Container:

    ```bash
    sudo docker compose build mariadb
    ```
This will restore your database to the Bedrock project.

#### Step 2: Migrate Themes
1. Copy your themes from the vanilla site to the Bedrock directory. Bedrock uses web/app/ instead of wp-content/, so copy themes accordingly:

    ```bash
    cp -R /path/to/vanillawp/wp-content/themes/* ./web/app/themes
    ```
#### Step 3: Migrate Plugins
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

#### Step 4: Migrate Uploads
Copy the uploads directory to web/app/uploads/ to match Bedrock’s structure:

```bash
rsync -vrz /path/to/vanillawp/wp-content/uploads/ ./web/app/uploads/
```

#### Step 5: Update Database References
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

### Usage Notes
- Database Management: Use phpMyAdmin to manage your database, if needed.
- Persistent Storage: Database and uploaded files are stored in Docker volumes, ensuring data persistence across container restarts.
- Custom Themes and Plugins: Place custom themes and plugins in `web/app/themes` and `web/app/plugins` respectively.

### Troubleshooting
If you encounter issues, consider the following:

- Database Connection Issues: Verify that the `.env` file has correct values for `DB_HOST`, `DB_NAME, DB_USER`, and `DB_PASSWORD`.
- Container Logs: Check Docker logs for specific containers with `sudo docker compose logs <container-name>`.
- Environment Configuration: Ensure `DOMAIN_CURRENT_SITE` in `.env` matches the URL you use to access the site.
- To fresh start the docker stack, do:
    ```bash
    sudo docker compose down -v --rmi all
    sudo docker compose up --build
    ```
> **Important Note:** This setup is designed for local development and is not recommended for production use. Always review configurations and optimize security settings when deploying to production environments.

### Acknowledgments
This project is inspired by Roots/Bedrock but is an independent setup for Docker-based WordPress multisite development and is not officially supported by Roots/Bedrock.