#!/bin/bash

# Purpose: This script sets up port forwarding for services running inside Docker containers in a WSL environment. It dynamically configures iptables rules to forward traffic from specified ports on the Docker network to the WSL gateway. If no ports are specified, the forwarding is skipped.

env_dir=. # .env directory
docker_dir=$env_dir # docker-compose.yml directory

# Array of ports to forward (leave empty to skip forwarding)
ports=() # Example: ports=(8069 8086)

# Ensure Docker is running
if ! systemctl is-active --quiet docker; then
    echo "Starting Docker daemon..."
    sudo systemctl start docker
fi

# Check if running on WSL
if grep -qiE "microsoft(.*wsl2?)?" /proc/version; then
    # Get the WSL gateway IP dynamically
    export WSL_HOST=$(ip route | grep default | awk '{print $3}')

    # Dynamically determine the Docker network source
    docker_network=$(ip route | grep docker0 | awk '{print $1}')

    # Check if ports array is empty
    if [ ${#ports[@]} -eq 0 ]; then
        echo "No ports specified. Skipping port forwarding setup."
    else
        for port in "${ports[@]}"; do
            # Add iptables rules for port forwarding to WSL Host
            if ! sudo iptables -t nat -C PREROUTING -p tcp -s $docker_network --dport $port -j DNAT --to-destination $WSL_HOST:$port 2>/dev/null; then
                sudo iptables -t nat -A PREROUTING -p tcp -s $docker_network --dport $port -j DNAT --to-destination $WSL_HOST:$port
                echo "Added PREROUTING rule for port $port from Docker Net ($docker_network) to WSL Host ($WSL_HOST)"
            else
                echo "PREROUTING rule for port $port from Docker Net ($docker_network) to WSL Host ($WSL_HOST) already exists"
            fi

            if ! sudo iptables -t nat -C POSTROUTING -s $docker_network -d $WSL_HOST -j MASQUERADE 2>/dev/null; then
                sudo iptables -t nat -A POSTROUTING -s $docker_network -d $WSL_HOST -j MASQUERADE
                echo "Added POSTROUTING MASQUERADE rule for traffic from Docker Net ($docker_network) to WSL Host ($WSL_HOST)"
            else
                echo "POSTROUTING MASQUERADE rule for traffic from Docker Net ($docker_network) to WSL Host ($WSL_HOST) already exists"
            fi
        done
    fi
fi

# Start Docker Compose services
echo "Starting Docker Compose services..."
sudo WSL_HOST=$WSL_HOST docker compose --env-file $env_dir/.env -f $docker_dir/docker-compose.yml up
