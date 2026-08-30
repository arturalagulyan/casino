# Casino — developer shortcuts
#
# Local development runs on Laravel Sail (Docker). Run these from the repo root
# inside WSL / a Linux shell (not PowerShell):  make <target>
# Run `make` with no arguments to list everything.

SAIL := ./vendor/bin/sail

.DEFAULT_GOAL := help

# ---------------------------------------------------------------------------

.PHONY: help
help: ## List available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

.PHONY: setup
setup: ## First-time setup: .env, dependencies, app key, database, assets
	@test -f .env || cp .env.example .env
	docker run --rm \
		-u "$$(id -u):$$(id -g)" \
		-v "$$(pwd):/var/www/html" \
		-w /var/www/html \
		laravelsail/php84-composer:latest \
		composer install --ignore-platform-reqs
	$(SAIL) up -d
	$(SAIL) artisan key:generate
	$(SAIL) artisan migrate --seed
	$(SAIL) npm install
	@echo ""
	@echo "  Setup complete.  Start developing with:  make dev"

.PHONY: dev
dev: guard-setup up ## Start EVERYTHING at once: containers + queue + logs + Vite (Ctrl+C to stop)
	$(SAIL) npx concurrently -k -n queue,logs,vite -c "green,magenta,cyan" \
		"php artisan queue:listen --tries=1 --timeout=0" \
		"php artisan pail --timeout=0" \
		"npm run dev -- --host"

.PHONY: up
up: ## Start the containers in the background
	$(SAIL) up -d

.PHONY: down
down: ## Stop the containers
	$(SAIL) down

.PHONY: restart
restart: ## Restart the containers
	$(SAIL) restart

.PHONY: ps
ps: ## Show container status
	$(SAIL) ps

.PHONY: logs
logs: ## Tail application logs (artisan pail)
	$(SAIL) artisan pail

.PHONY: shell
shell: ## Open a shell inside the app container
	$(SAIL) shell

.PHONY: tinker
tinker: ## Open a REPL (artisan tinker)
	$(SAIL) artisan tinker

.PHONY: migrate
migrate: ## Run new migrations
	$(SAIL) artisan migrate

.PHONY: fresh
fresh: ## Drop all tables, re-migrate, re-seed
	$(SAIL) artisan migrate:fresh --seed

.PHONY: test
test: ## Run the test suite
	$(SAIL) artisan test

.PHONY: pint
pint: ## Format code with Laravel Pint
	$(SAIL) pint

.PHONY: build
build: ## Build production front-end assets
	$(SAIL) npm run build

.PHONY: deploy
deploy: ## Push main and let GitHub Actions deploy to the server
	git push origin main

# ---------------------------------------------------------------------------

.PHONY: guard-setup
guard-setup:
	@test -x $(SAIL) || { echo "Sail is not installed yet — run:  make setup"; exit 1; }
	@test -f .env    || { echo "No .env file yet — run:  make setup"; exit 1; }
