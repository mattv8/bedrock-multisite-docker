# Bedrock Wordpress Multisite Docker Development Stack

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
The `.vscode` directory contains two helper scripts to simplify development:

- **start.sh**: Starts the Docker development environment, including setting up port forwarding for specified services. This ensures that Docker containers running inside WSL can communicate with services on the Windows host, if neecessary. This can be run with the shortcut Ctrl+Shift+B.
- **install.sh**: Installs WordPress dependencies and sets up the environment for the first time. You can execute it manually or via the "Install Dependencies" VSCode task.

To use these tasks in VSCode:
1. Open the VSCode Command Palette (Ctrl+Shift+P or Cmd+Shift+P).
2. Search for "Tasks: Run Task."
3. Select the task you want to run (e.g., "Start Bedrock Server" or "Install Dependencies").

Launch the Docker containers with Docker Compose:

```bash
sudo docker compose up --build
```
> **Note:** the `--build` flag is only necessary on first run.

### 4. Access the Site

Once the containers are running, you can access your WordPress site by navigating to [http://localhost:${NGINX_PORT}](http://localhost:81).

To manage your database, use phpMyAdmin, accessible at [http://localhost:${PHPMA_PORT}](http://localhost:82).

## Managing Uploads with MinIO

MinIO is an S3-compatible object storage solution included in this setup to handle media uploads and other assets in a scalable way. It integrates seamlessly with your `.env` file and the `Rewrite.php` mu-plugin to rewrite file URLs for compatibility with MinIO.

#### Why Use MinIO?
- **Local Development**: Test S3-compatible storage features locally without requiring a cloud provider.
- **Scalability**: Seamlessly transition to actual S3-compatible services in staging or production.
- **Multisite Support**: Easily manage media files across multiple sites.
- **Dynamic URL Rewriting**: The `Rewrite.php` mu-plugin ensures all uploaded files' URLs are dynamically rewritten to use the MinIO bucket URL, leveraging the `rewriteURL($url)` function for seamless integration.

#### How to Use MinIO

1. **Set Up MinIO:**
   - Access the MinIO web interface at [http://localhost:${MINIO_GUI}](http://localhost:9001).
   - Log in using the credentials defined in your `.env` file (`MINIO_ROOT_USER` and `MINIO_ROOT_PASSWORD`).
   - Create a bucket for your WordPress media (e.g., `wordpress-media`).
   - Make sure to set the bucket's *Access Policy* to *Public*

2. **Configure API Credentials:**
   Optionally, If you would like to enable media uploads directly to MinIO, you will need to configure an *Access Key*.
   - In the MinIO console, navigate to the **Identity** or **Users** section.
   - Create a new user and assign a policy that grants the new user access to your bucket (e.g. `readwrite`).
   - Then go to *Identity > Users > {your_user} > Service Accounts* and generate an Access Key and Secret Key.
   - Add these values as `MINIO_KEY` and `MINIO_SECRET` in your `.env` file.

4. **Configure Environment Variables:**
   - Update `MINIO_URL` (e.g., `http://localhost:9000`) and `MINIO_BUCKET` (e.g., `wordpress-media`) in your `.env` file.

> **Tip:** The dynamic URL rewriting ensures compatibility with both local and production setups. Adjust `MINIO_URL` in your `.env` as needed for staging or production environments.

## Migrating vanilla Wordpress to Bedrock WP
See [mysql/README.md](mysql/README.md)

## Usage Notes
- Database Management: Use phpMyAdmin to manage your database, if needed.
- Persistent Storage: Database and uploaded files are stored in Docker volumes, ensuring data persistence across container restarts.
- Custom Themes and Plugins: Place custom themes and plugins in `web/app/themes` and `web/app/plugins` respectively.

## Troubleshooting
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

## Acknowledgments
This project is inspired by Roots/Bedrock but is an independent setup for Docker-based WordPress multisite development and is not officially supported by Roots/Bedrock.
