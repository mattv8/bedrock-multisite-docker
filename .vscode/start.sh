#!/bin/bash

env_dir=. # .env directory
docker_dir=$env_dir # docker-compose.yml directory

# Ensure Docker is running
if ! systemctl is-active --quiet docker; then
    echo "Starting Docker daemon..."
    sudo systemctl start docker
fi

# Start Docker Compose services
echo "Starting Docker Compose services..."
sudo docker compose --env-file $env_dir/.env -f $docker_dir/docker-compose.yml up --build
