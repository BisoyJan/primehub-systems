#!/bin/bash

# Docker Setup Script for PrimeHub Systems
# This script automates the initial Docker setup

set -e

echo "🐳 PrimeHub Systems - Docker Setup"
echo "=================================="
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Error: Docker is not running. Please start Docker Desktop and try again."
    exit 1
fi

echo "✓ Docker is running"
echo ""

# Check if .env exists
if [ ! -f .env ]; then
    echo "📝 Creating .env file from .env.docker..."
    cp .env.docker .env
    echo "✓ .env file created"
else
    echo "ℹ️  .env file already exists, skipping..."
fi

echo ""
echo "🔨 Building Docker containers..."
docker-compose build

echo ""
echo "🚀 Starting Docker containers..."
docker-compose up -d

echo ""
echo "⏳ Waiting for database to be ready..."
sleep 10

echo ""
echo "📦 Installing PHP dependencies..."
docker-compose exec -T app composer install --no-interaction

echo ""
echo "📦 Installing Node dependencies..."
docker-compose exec -T node npm install

echo ""
echo "🔑 Generating application key..."
docker-compose exec -T app php artisan key:generate

echo ""
echo "🗄️  Running database migrations..."
docker-compose exec -T app php artisan migrate --force

echo ""
echo "🔐 Setting permissions..."
docker-compose exec -T app chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
docker-compose exec -T app chmod -R 775 /var/www/storage /var/www/bootstrap/cache

echo ""
echo "🎨 Building frontend assets..."
docker-compose exec -T node npm run build

echo ""
echo "=================================="
echo "✅ Docker setup complete!"
echo ""
echo "Your application is now running:"
echo "  🌐 Application: http://localhost"
echo "  ⚡ Vite Dev Server: http://localhost:5173"
echo "  🗄️  MySQL: localhost:3306"
echo "  📦 Redis: localhost:6379"
echo ""
echo "Useful commands:"
echo "  docker-compose ps              # View running containers"
echo "  docker-compose logs -f         # View logs"
echo "  docker-compose exec app bash   # Access app container"
echo "  docker-compose down            # Stop containers"
echo ""
echo "For more information, see DOCKER_SETUP.md"
echo "=================================="
