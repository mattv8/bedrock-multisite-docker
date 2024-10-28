#!/bin/bash

# Color codes
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m' # No color

env_dir=. # .env directory
docker_dir=$env_dir # docker-compose.yml directory

echo -e "Current directory: $(pwd)"

# Check if Docker is installed
check_docker_installed() {
    if ! command -v docker &> /dev/null; then
        echo -e "${RED}Docker is not installed. Installing Docker...${NC}"
        # 1. Required dependencies
        sudo apt-get update
        sudo apt-get -y install apt-transport-https ca-certificates curl gnupg lsb-release

        # 2. GPG key
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg

        # 3. Use stable repository for Docker
        echo \
        "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu \
        $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

        # 4. Install Docker
        sudo apt-get update
        sudo apt-get -y install docker-ce docker-ce-cli containerd.io

        # 5. Add user to docker group
        sudo groupadd docker || true
        sudo usermod -aG docker $USER
    fi
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
}

# Check if Docker daemon is running
check_docker_running() {
    if ! systemctl is-active --quiet docker; then
        echo -e "${YELLOW}Docker daemon is not running. Starting Docker daemon...${NC}"
        sudo systemctl start docker
    fi
}

# Check if Docker Compose images need to be built
check_docker_compose_build() {
    if ! sudo docker compose --env-file $env_dir/.env -f $docker_dir/docker-compose.yml config --services | xargs sudo docker images | grep -q "<none>"; then
        echo -e "Building Docker Compose images..."
        sudo docker compose --env-file $env_dir/.env -f $docker_dir/docker-compose.yml build
    fi
}

check_dot_env() {
    if [ ! -f $env_dir/.env ]; then
        cp $env_dir/.env.example $env_dir/.env
        echo -e "${RED}A .env file has been created from $env_dir/.env.example. Please edit it to configure your environment settings and run the build task again.${NC}"
        exit 1
    fi
}

# Run checks and installations
check_dot_env
check_docker_installed
check_docker_compose_installed
check_docker_running
check_docker_compose_build

# If Docker and Docker Compose are set up and running, exit with success status
if command -v docker &> /dev/null && docker compose version &> /dev/null && systemctl is-active --quiet docker; then
    sudo docker compose --env-file $env_dir/.env -f $docker_dir/docker-compose.yml up
else
    echo -e "${RED}Error: Docker or Docker Compose are not set up properly.${NC}"
    exit 1
fi