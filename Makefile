SHELL := /bin/bash
export UID := $(shell id -u)
export GID := $(shell id -g)

.PHONY: setup up down build logs shell artisan composer npm test lint typecheck

setup:
	./setup.sh

up:
	docker compose up -d --build

build:
	docker compose build

down:
	docker compose down

logs:
	docker compose logs -f app

shell:
	docker compose exec app bash

artisan:
	docker compose exec app php artisan $(ARGS)

composer:
	docker compose exec app composer $(ARGS)

npm:
	docker compose exec app npm $(ARGS)

test:
	docker compose exec app php artisan test

lint:
	docker compose exec app ./vendor/bin/pint

typecheck:
	docker compose exec app npm run typecheck
