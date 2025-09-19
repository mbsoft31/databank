.PHONY: help build up down restart logs shell test migrate seed fresh

# Default target
help:
	@echo "Mounir Databank Development Commands"
	@echo ""
	@echo "Usage:"
	@echo "  make <command>"
	@echo ""
	@echo "Available commands:"
	@echo "  build     Build Docker containers"
	@echo "  up        Start all services"
	@echo "  down      Stop all services"
	@echo "  restart   Restart all services"
	@echo "  logs      Show application logs"
	@echo "  shell     Access application shell"
	@echo "  test      Run tests"
	@echo "  migrate   Run database migrations"
	@echo "  seed      Seed database"
	@echo "  fresh     Fresh database with seed"

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f app

shell:
	docker compose exec app bash

test:
	docker compose exec app php artisan test

migrate:
	docker compose exec app php artisan migrate

seed:
	docker compose exec app php artisan db:seed

fresh:
	docker compose exec app php artisan migrate:fresh --seed
