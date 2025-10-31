.PHONY: help build up down restart logs shell test migrate fresh seed clear

# Default target
help:
	@echo "PrimeHub Systems - Docker Commands"
	@echo "==================================="
	@echo ""
	@echo "Setup Commands:"
	@echo "  make setup        - Initial setup (build, start, install, migrate)"
	@echo "  make build        - Build Docker containers"
	@echo "  make up           - Start all containers"
	@echo "  make down         - Stop all containers"
	@echo "  make restart      - Restart all containers"
	@echo ""
	@echo "Development Commands:"
	@echo "  make logs         - View all logs"
	@echo "  make shell        - Access app container shell"
	@echo "  make npm          - Access node container shell"
	@echo "  make mysql        - Access MySQL shell"
	@echo ""
	@echo "Laravel Commands:"
	@echo "  make migrate      - Run database migrations"
	@echo "  make fresh        - Fresh database with migrations"
	@echo "  make seed         - Seed database"
	@echo "  make test         - Run tests"
	@echo "  make clear        - Clear all caches"
	@echo "  make key          - Generate app key"
	@echo ""
	@echo "Asset Commands:"
	@echo "  make dev          - Start Vite dev server"
	@echo "  make build-assets - Build production assets"
	@echo ""
	@echo "Maintenance Commands:"
	@echo "  make queue        - View queue worker logs"
	@echo "  make queue-restart - Restart queue worker"
	@echo "  make permissions  - Fix storage permissions"
	@echo "  make clean        - Remove containers and volumes"

# Setup
setup:
	@echo "🚀 Setting up PrimeHub Systems..."
	cp -n .env.docker .env || true
	docker-compose build
	docker-compose up -d
	@echo "⏳ Waiting for database..."
	@sleep 10
	docker-compose exec -T app composer install
	docker-compose exec -T node npm install
	docker-compose exec -T app php artisan key:generate
	docker-compose exec -T app php artisan migrate --force
	@make permissions
	@echo "✅ Setup complete!"

# Container Management
build:
	docker-compose build

up:
	docker-compose up -d

down:
	docker-compose down

restart:
	docker-compose restart

logs:
	docker-compose logs -f

# Shell Access
shell:
	docker-compose exec app bash

npm:
	docker-compose exec node sh

mysql:
	docker-compose exec db mysql -u primehub_user -psecret primehub

# Laravel Commands
migrate:
	docker-compose exec app php artisan migrate

fresh:
	docker-compose exec app php artisan migrate:fresh

seed:
	docker-compose exec app php artisan db:seed

test:
	docker-compose exec app php artisan test

clear:
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	docker-compose exec app php artisan view:clear

key:
	docker-compose exec app php artisan key:generate

# Asset Management
dev:
	docker-compose exec node npm run dev

build-assets:
	docker-compose exec node npm run build

# Queue Management
queue:
	docker-compose logs -f queue

queue-restart:
	docker-compose restart queue

# Maintenance
permissions:
	docker-compose exec app chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
	docker-compose exec app chmod -R 775 /var/www/storage /var/www/bootstrap/cache

clean:
	docker-compose down -v
	@echo "⚠️  All containers and volumes removed!"
