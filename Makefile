.PHONY: help install test serve up down build

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies
	composer install

test: ## Run all tests
	vendor/bin/phpunit --testdox

test-unit: ## Run unit tests only
	vendor/bin/phpunit --testsuite Unit --testdox

test-integration: ## Run integration tests only
	vendor/bin/phpunit --testsuite Integration --testdox

serve: ## Start local development server
	php -S localhost:8080 -t public/

up: ## Start Docker container
	docker compose up --build -d

down: ## Stop Docker container
	docker compose down

build: ## Build Docker image
	docker compose build
