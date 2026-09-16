SHELL := /bin/bash

ROOT := $(CURDIR)
VENV := $(ROOT)/backend/.venv
PYTHON := $(VENV)/bin/python
PIP := $(VENV)/bin/pip
GUNICORN := $(VENV)/bin/gunicorn
RUN_DIR := $(ROOT)/.run
BACKEND_PID := $(RUN_DIR)/backend.pid
FRONTEND_PID := $(RUN_DIR)/frontend.pid
BACKEND_LOG := $(RUN_DIR)/backend.log
FRONTEND_LOG := $(RUN_DIR)/frontend.log
BACKEND_URL := http://127.0.0.1:8000
FRONTEND_URL := http://127.0.0.1:5173

.PHONY: help setup backend-deps frontend-deps build start stop restart status logs clean

help:
	@echo "Lokala fynd development commands"
	@echo
	@echo "  make setup    Create Python venv and install all dependencies"
	@echo "  make build    Compile the TypeScript frontend"
	@echo "  make start    Build and start backend + frontend in background"
	@echo "  make stop     Stop both background processes"
	@echo "  make restart  Stop and start both processes"
	@echo "  make status   Show process status and local URLs"
	@echo "  make logs     Follow backend and frontend logs"
	@echo "  make clean    Stop services and remove generated build/runtime files"

setup: backend-deps frontend-deps

backend-deps: $(VENV)/.requirements-installed

$(VENV)/.requirements-installed: backend/requirements.txt
	@echo "Creating Python environment and installing backend dependencies..."
	@python3 -m venv "$(VENV)"
	@"$(PIP)" install -r backend/requirements.txt
	@touch "$@"

frontend-deps: frontend/node_modules/.installed

frontend/node_modules/.installed: frontend/package.json frontend/package-lock.json
	@echo "Installing frontend dependencies..."
	@cd frontend && npm install
	@touch "$@"

build: frontend-deps
	@echo "Compiling TypeScript frontend..."
	@cd frontend && npm run build

start: setup build
	@mkdir -p "$(RUN_DIR)"
	@if [[ -f "$(BACKEND_PID)" ]] && kill -0 "$$(cat "$(BACKEND_PID)")" 2>/dev/null; then \
		echo "Backend is already running (PID $$(cat "$(BACKEND_PID)"))."; \
	else \
		echo "Starting Python backend on $(BACKEND_URL)..."; \
		cd "$(ROOT)"; \
		nohup "$(GUNICORN)" --workers 1 --bind 127.0.0.1:8000 --chdir backend --access-logfile - wsgi:app >"$(BACKEND_LOG)" 2>&1 & \
		echo $$! >"$(BACKEND_PID)"; \
	fi
	@for attempt in $$(seq 1 40); do \
		if curl -fsS "$(BACKEND_URL)/api/health" >/dev/null 2>&1; then break; fi; \
		if [[ $$attempt -eq 40 ]]; then \
			echo "Backend failed to start. See $(BACKEND_LOG)"; \
			tail -n 30 "$(BACKEND_LOG)" || true; \
			$(MAKE) --no-print-directory stop; \
			exit 1; \
		fi; \
		sleep 0.25; \
	done
	@if [[ -f "$(FRONTEND_PID)" ]] && kill -0 "$$(cat "$(FRONTEND_PID)")" 2>/dev/null; then \
		echo "Frontend is already running (PID $$(cat "$(FRONTEND_PID)"))."; \
	else \
		echo "Starting frontend on $(FRONTEND_URL)..."; \
		cd "$(ROOT)"; \
		nohup "$(PYTHON)" frontend/dev_server.py --host 127.0.0.1 --port 5173 --directory frontend/dist --api-url "$(BACKEND_URL)" >"$(FRONTEND_LOG)" 2>&1 & \
		echo $$! >"$(FRONTEND_PID)"; \
	fi
	@for attempt in $$(seq 1 40); do \
		if curl -fsS "$(FRONTEND_URL)/" >/dev/null 2>&1; then break; fi; \
		if [[ $$attempt -eq 40 ]]; then \
			echo "Frontend failed to start. See $(FRONTEND_LOG)"; \
			tail -n 30 "$(FRONTEND_LOG)" || true; \
			$(MAKE) --no-print-directory stop; \
			exit 1; \
		fi; \
		sleep 0.25; \
	done
	@echo
	@echo "Lokala fynd is running: $(FRONTEND_URL)"
	@echo "Backend API:             $(BACKEND_URL)/api/health"
	@echo "Run 'make stop' to shut everything down."

stop:
	@mkdir -p "$(RUN_DIR)"
	@set -u; \
	stop_service() { \
		local name="$$1" pid_file="$$2" expected="$$3"; \
		if [[ ! -f "$$pid_file" ]]; then echo "$$name is not running."; return; fi; \
		local pid; pid="$$(cat "$$pid_file")"; \
		if [[ ! "$$pid" =~ ^[0-9]+$$ ]] || ! kill -0 "$$pid" 2>/dev/null; then \
			echo "$$name is not running (removing stale PID file)."; rm -f "$$pid_file"; return; \
		fi; \
		local command; command="$$(tr '\0' ' ' <"/proc/$$pid/cmdline" 2>/dev/null || true)"; \
		if [[ "$$command" != *"$$expected"* ]]; then \
			echo "Refusing to stop PID $$pid: it is not the expected $$name process."; return 1; \
		fi; \
		echo "Stopping $$name (PID $$pid)..."; kill "$$pid"; \
		for _ in $$(seq 1 50); do kill -0 "$$pid" 2>/dev/null || break; sleep 0.1; done; \
		if kill -0 "$$pid" 2>/dev/null; then echo "$$name did not stop in time; sending SIGKILL."; kill -KILL "$$pid"; fi; \
		rm -f "$$pid_file"; \
	}; \
	stop_service "frontend" "$(FRONTEND_PID)" "frontend/dev_server.py"; \
	stop_service "backend" "$(BACKEND_PID)" "gunicorn"

restart: stop start

status:
	@set -u; \
	show_service() { \
		local name="$$1" pid_file="$$2" url="$$3"; \
		if [[ -f "$$pid_file" ]] && kill -0 "$$(cat "$$pid_file")" 2>/dev/null; then \
			echo "$$name: running (PID $$(cat "$$pid_file")) — $$url"; \
		else \
			echo "$$name: stopped"; \
		fi; \
	}; \
	show_service "backend" "$(BACKEND_PID)" "$(BACKEND_URL)"; \
	show_service "frontend" "$(FRONTEND_PID)" "$(FRONTEND_URL)"

logs:
	@mkdir -p "$(RUN_DIR)"
	@touch "$(BACKEND_LOG)" "$(FRONTEND_LOG)"
	@tail -n 50 -F "$(BACKEND_LOG)" "$(FRONTEND_LOG)"

clean: stop
	@echo "Removing generated frontend and runtime files..."
	@rm -rf "$(ROOT)/frontend/dist"
	@rm -f "$(BACKEND_LOG)" "$(FRONTEND_LOG)"
