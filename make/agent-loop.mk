# agent-loop asset targets for a host repository.
#
# Include this file from the host Makefile instead of re-declaring one wrapper
# target per client:
#
#   -include vendor/voku/agent-loop/make/agent-loop.mk
#
# Everything is overridable with `?=` semantics, so a host that needs its own
# entrypoint (extra bootstrap, raised memory limit, container dispatch) only
# sets AGENT_LOOP_BIN and keeps every target below:
#
#   AGENT_LOOP_BIN := php -d memory_limit=4G tools/agent-loop-entrypoint.php
#
# The canonical asset roots stay in the host repository and are resolved from
# AGENT_LOOP_CONFIG; this file owns the commands, not the content.

AGENT_LOOP_BIN ?= vendor/bin/agent-loop
AGENT_LOOP_CONFIG ?= .agent-loop/init.json
# --force keeps a repeated sync idempotent: canonical skills do change, and the
# target content is a generated copy, not a hand-edited file.
AGENT_LOOP_SYNC_FLAGS ?= --force
AGENT_LOOP_INIT = $(AGENT_LOOP_BIN) init
AGENT_LOOP_WITH_CONFIG = --config "$(AGENT_LOOP_CONFIG)"

.PHONY: agent_init_doctor ## diagnose agent-loop init path resolution and installed agent assets
agent_init_doctor:
	$(AGENT_LOOP_INIT) doctor $(AGENT_LOOP_WITH_CONFIG)

.PHONY: agent_init_install_plan ## print a reviewed install plan, e.g. make agent_init_install_plan PROFILE=wsl2 AGENT=codex
agent_init_install_plan:
	@if [ -z "$(PROFILE)" ] || [ -z "$(AGENT)" ]; then \
		echo "❌ Missing PROFILE or AGENT parameter"; \
		echo "   Usage: make agent_init_install_plan PROFILE=wsl2|linux|windows AGENT=codex|claude|opencode|copilot|gemini|antigravity"; \
		exit 1; \
	fi
	$(AGENT_LOOP_INIT) install-plan --profile "$(PROFILE)" --agent "$(AGENT)"

.PHONY: agent_init_status ## show which agent assets are installed and managed
agent_init_status:
	$(AGENT_LOOP_INIT) status $(AGENT_LOOP_WITH_CONFIG)

.PHONY: agent_init_host_status ## discover/converge the active coding host without mutating it
agent_init_host_status:
	$(AGENT_LOOP_INIT) host-status --format=json

.PHONY: validate_agent_skills ## validate repo-managed agent skill definitions
validate_agent_skills:
	$(AGENT_LOOP_INIT) validate --kind=skills $(AGENT_LOOP_WITH_CONFIG)

.PHONY: validate_agent_subagents ## validate repo-managed agent subagent definitions
validate_agent_subagents:
	$(AGENT_LOOP_INIT) validate --kind=subagents $(AGENT_LOOP_WITH_CONFIG)

.PHONY: validate_codex_hooks ## validate repo-managed Codex hook definitions
validate_codex_hooks:
	$(AGENT_LOOP_INIT) validate --kind=hooks --agent=codex $(AGENT_LOOP_WITH_CONFIG)

.PHONY: validate_claude_hooks ## validate repo-managed Claude Code hook definitions
validate_claude_hooks:
	$(AGENT_LOOP_INIT) validate --kind=hooks --agent=claude $(AGENT_LOOP_WITH_CONFIG)

.PHONY: validate_agent_assets ## validate every repo-managed agent asset kind
validate_agent_assets: validate_agent_skills validate_agent_subagents validate_codex_hooks validate_claude_hooks

.PHONY: install_codex_skills ## sync repo-managed skills for Codex
install_codex_skills:
	$(AGENT_LOOP_INIT) sync-skills --agent=codex $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_claude_skills ## sync repo-managed skills for Claude Code
install_claude_skills:
	$(AGENT_LOOP_INIT) sync-skills --agent=claude $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_opencode_skills ## sync repo-managed skills for OpenCode
install_opencode_skills:
	$(AGENT_LOOP_INIT) sync-skills --agent=opencode $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_copilot_skills ## sync repo-managed skills for GitHub Copilot
install_copilot_skills:
	$(AGENT_LOOP_INIT) sync-skills --agent=copilot $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_gemini_skills ## sync repo-managed skills for Gemini CLI
install_gemini_skills:
	$(AGENT_LOOP_INIT) sync-skills --agent=gemini $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_antigravity_skills ## sync repo-managed skills for Antigravity
install_antigravity_skills:
	$(AGENT_LOOP_INIT) sync-skills --agent=antigravity $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_agent_skills ## sync repo-managed skills for every canonical client
install_agent_skills:
	$(AGENT_LOOP_INIT) sync-skills --agent=all $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_codex_agents ## sync repo-managed subagents for Codex
install_codex_agents:
	$(AGENT_LOOP_INIT) sync-subagents --agent=codex $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_claude_agents ## sync repo-managed subagents for Claude Code
install_claude_agents:
	$(AGENT_LOOP_INIT) sync-subagents --agent=claude $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_opencode_agents ## sync repo-managed subagents for OpenCode
install_opencode_agents:
	$(AGENT_LOOP_INIT) sync-subagents --agent=opencode $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_copilot_agents ## sync repo-managed subagents for GitHub Copilot
install_copilot_agents:
	$(AGENT_LOOP_INIT) sync-subagents --agent=copilot $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_gemini_agents ## sync repo-managed subagents for Gemini CLI
install_gemini_agents:
	$(AGENT_LOOP_INIT) sync-subagents --agent=gemini $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_antigravity_agents ## sync repo-managed subagents for Antigravity
install_antigravity_agents:
	$(AGENT_LOOP_INIT) sync-subagents --agent=antigravity $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_agent_subagents ## sync repo-managed subagents for every supported client
install_agent_subagents:
	$(AGENT_LOOP_INIT) sync-subagents --agent=all $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_codex_hooks ## sync repo-managed hooks into the Codex client directory
install_codex_hooks:
	$(AGENT_LOOP_INIT) sync-hooks --agent=codex $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_claude_hooks ## sync repo-managed hooks into the Claude Code settings file
install_claude_hooks:
	$(AGENT_LOOP_INIT) sync-hooks --agent=claude $(AGENT_LOOP_WITH_CONFIG) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_agent_hooks ## sync repo-managed hooks for every client that supports them
install_agent_hooks: install_codex_hooks install_claude_hooks

.PHONY: install_agent_assets ## sync every repo-managed agent asset kind
install_agent_assets: install_agent_skills install_agent_subagents install_agent_hooks

# Package-owned Git hooks (agent-map index refresh on post-merge / post-checkout) plus
# core.hooksPath and commit.template. Host-owned hooks in the same directory are untouched.
# Point these at the host setup, e.g.:
#   AGENT_LOOP_GITHOOKS_DIR := .githooks
#   AGENT_LOOP_COMMIT_TEMPLATE := .gitmessage
#   AGENT_LOOP_GITHOOKS_ARGS := --container-service=php --container-image=my-php --container-workdir=/var/www/html --container-user=www-data
AGENT_LOOP_GITHOOKS_DIR ?= .githooks
AGENT_LOOP_COMMIT_TEMPLATE ?=
AGENT_LOOP_GITHOOKS_ARGS ?=

.PHONY: install_githooks ## install package-owned Git hooks and point Git at them
install_githooks:
	$(AGENT_LOOP_INIT) sync-githooks --hooks-dir "$(AGENT_LOOP_GITHOOKS_DIR)" \
		$(if $(AGENT_LOOP_COMMIT_TEMPLATE),--commit-template "$(AGENT_LOOP_COMMIT_TEMPLATE)",) \
		$(AGENT_LOOP_GITHOOKS_ARGS) $(AGENT_LOOP_SYNC_FLAGS)

.PHONY: install_githooks_dry ## preview the Git hook installation without writing anything
install_githooks_dry:
	$(AGENT_LOOP_INIT) sync-githooks --hooks-dir "$(AGENT_LOOP_GITHOOKS_DIR)" \
		$(if $(AGENT_LOOP_COMMIT_TEMPLATE),--commit-template "$(AGENT_LOOP_COMMIT_TEMPLATE)",) \
		$(AGENT_LOOP_GITHOOKS_ARGS) --dry-run
