# Hive Commerce — convenience wrappers for the docker stacks.
# All recipes are thin shells around `docker compose`; if you don't use
# make, the equivalent commands are documented in docker/README.md.

DEV     := docker compose -f docker/docker-compose.dev.yml  --env-file docker/.env.dev
PROD    := docker compose -f docker/docker-compose.prod.yml --env-file docker/.env.prod

.PHONY: help \
        dev-env dev-up dev-down dev-logs dev-shell dev-init dev-test dev-wp dev-reset \
        prod-env prod-up prod-down prod-logs prod-deploy prod-build prod-backup-now

help:
	@awk 'BEGIN{FS=":.*##"; printf "\nTargets:\n"} /^[a-zA-Z0-9_-]+:.*##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

# ---- dev ----------------------------------------------------------------

dev-env: ## Create docker/.env.dev from the example if missing
	@test -f docker/.env.dev || cp docker/.env.dev.example docker/.env.dev

dev-up: dev-env ## Build and start the local stack
	$(DEV) up -d --build

dev-down: ## Stop the local stack (volumes preserved)
	$(DEV) down

dev-reset: ## Stop and DROP volumes (wipes WP install + DB)
	$(DEV) down -v

dev-logs: ## Tail logs from all services
	$(DEV) logs -f --tail=100

dev-shell: ## Bash shell inside the wordpress container
	$(DEV) exec wordpress bash

dev-init: ## Install WP core + WooCommerce + activate the plugin
	$(DEV) exec wordpress bash docker/scripts/wp-init.sh

dev-test: ## Run the PHPUnit suite inside the container
	$(DEV) exec wordpress bash docker/scripts/test.sh

dev-wp: ## Run a one-off wp-cli command, e.g. `make dev-wp ARGS="plugin list"`
	$(DEV) run --rm wpcli $(ARGS)

# ---- prod (run on the VPS) ----------------------------------------------

prod-env: ## Create docker/.env.prod from the example if missing
	@test -f docker/.env.prod || cp docker/.env.prod.example docker/.env.prod

prod-build: ## Build the production wordpress image
	$(PROD) build --pull wordpress

prod-up: ## Start (or update) the production stack
	$(PROD) up -d --remove-orphans

prod-down: ## Stop the production stack
	$(PROD) down

prod-logs: ## Tail production logs
	$(PROD) logs -f --tail=100

prod-deploy: ## Pull, rebuild and roll the stack
	bash docker/scripts/deploy.sh

prod-backup-now: ## Trigger an immediate DB dump
	$(PROD) exec db-backup sh -c 'mariadb-dump -h $$DB_HOST -u $$DB_USER -p$$DB_PASSWORD --single-transaction --quick --routines --triggers $$DB_NAME | gzip > /backups/$$DB_NAME-manual-$$(date +%Y%m%d-%H%M%S).sql.gz'
