#!/bin/bash

# Color codes
RED='\033[0;31m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No color

env_dir=. # .env directory
docker_dir=$env_dir # docker-compose.yml directory
bedrock_dir=$env_dir # docker-compose.yml directory
themes_dir="web/app/themes" # Themes directory
export USER_ID=$(id -u)
export GROUP_ID=$(id -g)

echo -e "${Yellow}Current directory: $(pwd)${NC}"
echo -e "${Yellow}Using USER_ID=${USER_ID} and GROUP_ID=${GROUP_ID} for Docker containers.${NC}"

# Check if .env file exists
check_dot_env() {
    if [ ! -f $env_dir/.env ]; then
        cp $env_dir/.env.example $env_dir/.env
        echo -e "${RED}A .env file has been created from $env_dir/.env.example. Please edit it to configure your environment settings and run the build task again.${NC}"
        exit 1
    fi
}

# Load .env variables
load_env_variables() {
    if [ -f "$env_dir/.env" ]; then
        echo -e "${YELLOW}Loading environment variables from .env file...${NC}"

        # Read and process each line of the .env file
        while IFS= read -r line || [ -n "$line" ]; do
            # Trim leading and trailing whitespace
            line=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

            # Skip comments and empty lines
            if [[ "$line" =~ ^#.*$ || -z "$line" ]]; then
                continue
            fi

            # Ensure the line is valid for export
            if [[ ! "$line" =~ ^[a-zA-Z_][a-zA-Z0-9_]*= ]]; then
                echo -e "${RED}Warning: Invalid line in .env file: $line${NC}"
                continue
            fi

            # Export the variable, allowing for quotes and string replacements
            eval "export $line"
        done < "$env_dir/.env"

        echo -e "${BLUE}Current Environment: ${WP_ENV}${NC}"
    else
        echo -e "${RED}Error: .env file not found in $env_dir.${NC}"
        exit 1
    fi
}

# Check if Docker is installed
check_docker_installed() {
    if ! command -v docker &> /dev/null; then
        echo -e "${RED}Docker is not installed. Installing Docker...${NC}"
        # Add Docker's official GPG key:
        sudo apt-get update
        sudo apt-get install ca-certificates curl
        sudo install -m 0755 -d /etc/apt/keyrings
        sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
        sudo chmod a+r /etc/apt/keyrings/docker.asc

        # Add the repository to Apt sources:
        echo \
        "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian \
        $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
        sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
        sudo apt-get update
        sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    fi
    echo -e "${BLUE}Docker Version: $(docker --version)${NC}"
}

# Check if Docker Compose v2 is installed
check_docker_compose_installed() {
    if ! sudo docker compose version &> /dev/null; then
        echo -e "${YELLOW}Docker Compose v2 is not installed. Installing Docker Compose v2...${NC}"
        DOCKER_CONFIG=${DOCKER_CONFIG:-$HOME/.docker}
        mkdir -p $DOCKER_CONFIG/cli-plugins
        curl -SL https://github.com/docker/compose/releases/download/v2.22.0/docker-compose-linux-x86_64 -o $DOCKER_CONFIG/cli-plugins/docker-compose
        chmod +x $DOCKER_CONFIG/cli-plugins/docker-compose
    fi
    echo -e "${BLUE}Docker Compose Version: $(sudo docker compose version | head -n 1)${NC}"
}

# Install PHP and Composer
install_php_composer() {

    # Check if PHP is installed
    if ! command -v php &> /dev/null; then
        echo -e "${BLUE}PHP is not installed. Installing PHP...${NC}"
        sudo apt-get update -y
        sudo apt-get install -y php-cli php-mbstring unzip curl
    fi
    echo -e "${BLUE}PHP Version: $(php -v | head -n 1)${NC}"

    # Check if Composer is installed
    if ! command -v composer &> /dev/null; then
        echo -e "${BLUE}Composer is not installed. Installing Composer...${NC}"

        # Download Composer installer script
        curl -sS https://getcomposer.org/installer | php -- --quiet

        # Move composer to global bin directory
        sudo mv composer.phar /usr/local/bin/composer
    fi
    echo -e "${BLUE}Composer Version: $(COMPOSER_NO_INTERACTION=1 composer --version 2>&1 | grep -oP '(?<=Composer version ).*')${NC}"
}

# Auto-installs PHP extensions needed by composer plugins
install_php_extension() {
    local ext=$1
    echo -e "${RED}Missing PHP extension: $ext. Installing...${NC}"
    if ! sudo apt-get install -y "php-${ext}"; then
        echo -e "${RED}Failed to install PHP extension: $ext. Please check your configuration.${NC}"
        exit 1
    fi
    echo -e "${GREEN}PHP extension $ext installed successfully.${NC}"
}

# Check if Node.js is installed
check_node_installed() {
    if ! command -v node &> /dev/null; then
        echo -e "${YELLOW}Node.js is not installed. Setting up Node.js with NVM...${NC}"

        # Check if NVM is installed
        if [ ! -d "$HOME/.nvm" ] && [ "$USER_ID" -ne 0 ]; then
            echo -e "${YELLOW}NVM is not installed for the current user. Installing NVM...${NC}"
            curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.3/install.sh | bash
        elif [ "$USER_ID" -eq 0 ]; then
            echo -e "${YELLOW}Running as root. Ensuring NVM is set up for root...${NC}"
            export NVM_DIR="/root/.nvm"
            if [ ! -d "$NVM_DIR" ]; then
                echo -e "${YELLOW}NVM is not installed for root. Installing NVM...${NC}"
                curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.3/install.sh | bash
            fi
        fi

        # Ensure NVM_DIR points to the correct directory
        if [ "$USER_ID" -ne 0 ]; then
            export NVM_DIR="$HOME/.nvm"
        fi

        # Create the NVM_DIR if it doesn't exist
        if [ ! -d "$NVM_DIR" ]; then
            echo -e "${BLUE}NVM directory does not exist. Creating $NVM_DIR...${NC}"
            mkdir -p "$NVM_DIR"
        fi

        # Load NVM into the current shell session
        if [ -s "$NVM_DIR/nvm.sh" ]; then
            . "$NVM_DIR/nvm.sh" # Load nvm
        else
            echo -e "${RED}NVM script not found in $NVM_DIR. Please install NVM manually.${NC}"
            exit 1
        fi

        # Load NVM bash_completion if available
        if [ -s "$NVM_DIR/bash_completion" ]; then
            . "$NVM_DIR/bash_completion" # Load nvm bash_completion
        fi

        # Install the latest LTS version of Node.js
        echo -e "${YELLOW}Installing the latest LTS version of Node.js...${NC}"
        nvm install --lts
    fi

    # Ensure npm is installed
    if ! command -v npm &> /dev/null; then
        echo -e "${RED}NPM was not installed properly. Please check your Node.js setup.${NC}"
        exit 1
    fi
    echo -e "${BLUE}Node.js Version: $(node -v)${NC}"
    echo -e "${BLUE}NPM Version: $(npm -v)${NC}"
}

# Check if Bedrock is set up
check_bedrock() {
    if [ -f "$bedrock_dir/web/index.php" ] && [ -n "$(ls -A "$bedrock_dir/web/wp")" ]; then
        echo -e "${YELLOW}Setting up Bedrock WordPress...${NC}"

        # Ensure Composer does not prompt for root user confirmation
        export COMPOSER_ALLOW_SUPERUSER=1

        # Run composer install and capture output
        echo -e "${BLUE}Running composer install...${NC}"
        composer_output=$(sudo composer install 2>&1 || true)

        # Check for missing PHP extensions
        if echo "$composer_output" | grep -q "ext-"; then
            echo -e "${RED}Composer detected missing PHP extensions.${NC}"

            # Extract missing extensions and install them
            missing_extensions=$(echo "$composer_output" | grep -oP '(?<=ext-)[a-zA-Z0-9_]+')
            for ext in $missing_extensions; do
                install_php_extension "$ext"
            done

            # Retry composer install after installing extensions
            echo -e "${BLUE}Re-running composer install after installing extensions...${NC}"
            sudo composer install || {
                echo -e "${RED}Composer install failed after installing extensions. Please check manually.${NC}"
                exit 1
            }
        elif echo "$composer_output" | grep -q "Your lock file does not contain a compatible set of packages"; then
            echo -e "${RED}Composer failed due to incompatible package versions. Run 'composer update' manually.${NC}"
            exit 1
        fi

        # Check if the WordPress directory exists after running composer install
        if [ -f "$bedrock_dir/web/wp/index.php" ]; then
            echo -e "${GREEN}Composer install completed successfully, and WordPress directory is ready.${NC}"
        else
            echo -e "${RED}Failed to set up Bedrock WordPress. Please check the logs and try again.${NC}"
            exit 1
        fi

        # Re-own necessary directories
        echo -e "${BLUE}Adjusting ownership for necessary directories...${NC}"
        for dir in "$bedrock_dir/vendor" "$bedrock_dir/web"; do
            if [ -d "$dir" ]; then
                echo -e "${YELLOW}Re-owning $dir to USER_ID:GROUP_ID=${USER_ID}:${GROUP_ID}...${NC}"
                sudo chown -R "${USER_ID}:${GROUP_ID}" "$dir" && echo -e "${GREEN}Ownership updated for $dir.${NC}"
            fi
        done

    else
        echo -e "${GREEN}Bedrock WordPress is already set up.${NC}"
    fi
}

# Function to generate dynamic grants.sql and prepare SQL dumps for MariaDB
prepare_mariadb_sql() {
    local mysql_dir="./mysql"

    # Check if ./mysql directory exists
    if [ ! -d "$mysql_dir" ]; then
        echo -e "${RED}Directory $mysql_dir does not exist. Creating it...${NC}"
        mkdir -p "$mysql_dir"
    fi

    # Create grants.sql with replaced environment variables
    cat > "$mysql_dir/_grants.sql" <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
EOF
    echo -e "${BLUE}Generated dynamic grants.sql at $mysql_dir/_grants.sql.${NC}"

    # Set permissions for the SQL files
    chmod 644 "$mysql_dir"/*.sql
}

# Run checks
check_dot_env
load_env_variables
check_docker_installed
check_docker_compose_installed
install_php_composer
check_node_installed
check_bedrock
prepare_mariadb_sql

# Composer
echo -e "${BLUE}Composer installing...${NC}"
composer install

# Build and start Docker containers
echo -e "${BLUE}Building Docker containers...${NC}"
sudo docker compose build
