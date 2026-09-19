# agent-loop asset and workflow targets for a host repository.
#
# Include this file from the host Makefile instead of re-declaring one wrapper
# target per client or lifecycle operation:
#
#   -include vendor/voku/agent-loop/resources/make/agent-loop.mk
#
# Host configuration variables use `?=` semantics. A host that only needs its
# own entrypoint (extra bootstrap or a raised memory limit) can set
# AGENT_LOOP_BIN and keep every target below:
#
#   AGENT_LOOP_BIN := php -d memory_limit=4G tools/agent-loop-entrypoint.php
#
# The canonical asset roots stay in the host repository and are resolved from
# AGENT_LOOP_CONFIG; this file owns generic commands, not host content or runtime
# integration.

AGENT_LOOP_BIN ?= vendor/bin/agent-loop
AGENT_LOOP_CONFIG ?= .agent-loop/init.json
# --force keeps a repeated sync idempotent: canonical skills do change, and the
# target content is a generated copy, not a hand-edited file.
AGENT_LOOP_SYNC_FLAGS ?= --force
AGENT_LOOP_INIT = $(AGENT_LOOP_BIN) init
AGENT_LOOP_WITH_CONFIG = --config "$(AGENT_LOOP_CONFIG)"
AGENT_LOOP_DEFAULT_ACTOR ?= $(USER)
AGENT_LOOP_DEFAULT_VALIDATION ?= composer ci
AGENT_LOOP_QUOTE ?= '$(subst ','"'"',$(1))'

# Hosts that must enter a container, bootstrap an application, or select a user
# can define this function before including the file. Its arguments are the
# command, a short target name, and an optional run-as user:
#
# define AGENT_LOOP_RUN
# $(call RUN_IN_HOST_CONTEXT,$(1),$(2),$(3))
# endef
#
# The default remains a direct package-binary execution for portable projects.
ifndef AGENT_LOOP_RUN
define AGENT_LOOP_RUN
$(1)
endef
endif

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

.PHONY: agent_init_tools ## probe and cache reachability of CLI tools
agent_init_tools:
	$(AGENT_LOOP_INIT) tools $(ARGS)

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

# Generic governed workflow targets. Hosts retain runtime/environment ownership by
# defining AGENT_LOOP_RUN; this include retains only package-owned argv shaping.

.PHONY: agent_workflow_plan ## plan a governed task, e.g. make agent_workflow_plan TASK=TASK-123 FILE=src/Foo.php GOAL="..."
agent_workflow_plan:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_plan TASK=TASK-123 FILE=src/Foo.php GOAL=\"...\""; exit 1; }
	@test -n "$(FILE)" || { echo "FILE is required, e.g. make agent_workflow_plan TASK=TASK-123 FILE=src/Foo.php GOAL=\"...\""; exit 1; }
	@test -n "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" || { echo "BY or AGENT_LOOP_DEFAULT_ACTOR is required."; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow plan "$(TASK)" --by "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" --file "$(FILE)" $(if $(FILE2),--file "$(FILE2)",) --goal $(call AGENT_LOOP_QUOTE,$(if $(GOAL),$(GOAL),Task $(TASK))) --validation $(call AGENT_LOOP_QUOTE,$(if $(VALIDATION),$(VALIDATION),$(AGENT_LOOP_DEFAULT_VALIDATION))) $(ARGS),agent_workflow_plan,)

.PHONY: agent_workflow_approve ## approve a candidate Contract, e.g. make agent_workflow_approve TASK=TASK-123 BY=reviewer
agent_workflow_approve:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_approve TASK=TASK-123 BY=reviewer"; exit 1; }
	@test -n "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" || { echo "BY or AGENT_LOOP_DEFAULT_ACTOR is required."; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow approve "$(TASK)" --by "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" $(ARGS),agent_workflow_approve,)

.PHONY: agent_workflow_enter ## enter the canonical lifecycle, e.g. make agent_workflow_enter TASK=TASK-123
agent_workflow_enter:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_enter TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) enter "$(TASK)" --format=json $(ARGS),agent_workflow_enter,)

.PHONY: agent_workflow_finish ## reconcile governed close-out, e.g. make agent_workflow_finish TASK=TASK-123
agent_workflow_finish:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_finish TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) finish "$(TASK)" --format=json $(ARGS),agent_workflow_finish,)

.PHONY: agent_workflow_quick ## initiate and enter a surgical micro-task, e.g. make agent_workflow_quick TASK=TASK-123 GOAL="..." FILE=src/Foo.php
agent_workflow_quick:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_quick TASK=TASK-123 GOAL=\"...\" FILE=src/Foo.php"; exit 1; }
	@test -n "$(GOAL)" || { echo "GOAL is required, e.g. make agent_workflow_quick TASK=TASK-123 GOAL=\"...\" FILE=src/Foo.php"; exit 1; }
	@test -n "$(FILE)" || { echo "FILE is required, e.g. make agent_workflow_quick TASK=TASK-123 GOAL=\"...\" FILE=src/Foo.php"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) quick "$(TASK)" $(call AGENT_LOOP_QUOTE,$(GOAL)) --file="$(FILE)" $(if $(FILE2),--file="$(FILE2)",) $(if $(VERIFY),--verify=$(call AGENT_LOOP_QUOTE,$(VERIFY)),) $(if $(BY),--actor="$(BY)",) $(ARGS),agent_workflow_quick,)

.PHONY: agent_workflow_repair ## inspect bounded validation repair, e.g. make agent_workflow_repair TASK=TASK-123
agent_workflow_repair:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_repair TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) repair "$(TASK)" $(ARGS),agent_workflow_repair,)

.PHONY: agent_workflow_pipeline ## run a configured pipeline command, e.g. make agent_workflow_pipeline CMD=status TASK=TASK-123
agent_workflow_pipeline:
	@test -n "$(CMD)" || { echo "CMD is required (status|stage|run|submit), e.g. make agent_workflow_pipeline CMD=status TASK=TASK-123"; exit 1; }
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_pipeline CMD=status TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) pipeline $(CMD) "$(TASK)" $(if $(PROFILE),--profile "$(PROFILE)",) $(if $(BY),--by "$(BY)",) $(ARGS),agent_workflow_pipeline,)

.PHONY: agent_workflow_contract ## bind a ready L1 contract, e.g. make agent_workflow_contract TASK=TASK-123 FROM=.agent-loop/recall/TASK-123/execution-contract.md BY=reviewer
agent_workflow_contract:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_contract TASK=TASK-123 FROM=path BY=reviewer"; exit 1; }
	@test -n "$(FROM)" || { echo "FROM is required, e.g. make agent_workflow_contract TASK=TASK-123 FROM=path BY=reviewer"; exit 1; }
	@test -n "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" || { echo "BY or AGENT_LOOP_DEFAULT_ACTOR is required."; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow contract "$(TASK)" --status "$(if $(STATUS),$(STATUS),ready)" --from "$(FROM)" --by "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" $(ARGS),agent_workflow_contract,)

.PHONY: agent_workflow_execution_profile ## select a profile, e.g. make agent_workflow_execution_profile TASK=TASK-123 PROFILE=standard BY=reviewer
agent_workflow_execution_profile:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_execution_profile TASK=TASK-123 PROFILE=standard BY=reviewer"; exit 1; }
	@test -n "$(PROFILE)" || { echo "PROFILE is required (manual|surgical|standard|hardened)."; exit 1; }
	@test -n "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" || { echo "BY or AGENT_LOOP_DEFAULT_ACTOR is required."; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow execution-profile "$(TASK)" --profile "$(PROFILE)" --by "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" $(ARGS),agent_workflow_execution_profile,)

.PHONY: agent_workflow_attention ## resolve workflow attention, e.g. make agent_workflow_attention TASK=TASK-123 RESOLVE=attention-id BY=reviewer
agent_workflow_attention:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_attention TASK=TASK-123 RESOLVE=attention-id BY=reviewer"; exit 1; }
	@test -n "$(RESOLVE)" || { echo "RESOLVE is required."; exit 1; }
	@test -n "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" || { echo "BY or AGENT_LOOP_DEFAULT_ACTOR is required."; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow attention "$(TASK)" --resolve "$(RESOLVE)" --by "$(if $(BY),$(BY),$(AGENT_LOOP_DEFAULT_ACTOR))" $(ARGS),agent_workflow_attention,)

.PHONY: agent_workflow_status ## inspect workflow status, e.g. make agent_workflow_status TASK=TASK-123
agent_workflow_status:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_status TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow status "$(TASK)" $(ARGS),agent_workflow_status,)

.PHONY: agent_workflow_manifest ## inspect or write a run manifest, e.g. make agent_workflow_manifest TASK=TASK-123
agent_workflow_manifest:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_manifest TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow manifest "$(TASK)" $(ARGS),agent_workflow_manifest,)

.PHONY: agent_workflow_context ## inspect bounded workflow context, e.g. make agent_workflow_context TASK=TASK-123
agent_workflow_context:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_context TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow context "$(TASK)" $(ARGS),agent_workflow_context,)

.PHONY: agent_workflow_report ## inspect a completion report, e.g. make agent_workflow_report TASK=TASK-123
agent_workflow_report:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_report TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow report "$(TASK)" $(ARGS),agent_workflow_report,)

.PHONY: agent_workflow_transparency ## inspect scope and review evidence, e.g. make agent_workflow_transparency TASK=TASK-123
agent_workflow_transparency:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_transparency TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow transparency "$(TASK)" $(ARGS),agent_workflow_transparency,)

.PHONY: agent_workflow_review ## write a human review projection, e.g. make agent_workflow_review TASK=TASK-123
agent_workflow_review:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_review TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow review "$(TASK)" $(ARGS),agent_workflow_review,)

.PHONY: agent_workflow_reflect ## emit a bounded future-work reflection, e.g. make agent_workflow_reflect TASK=TASK-123
agent_workflow_reflect:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_reflect TASK=TASK-123"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow reflect "$(TASK)" $(if $(SCOPE),--scope "$(SCOPE)",) $(ARGS),agent_workflow_reflect,)

.PHONY: agent_workflow_handoff ## compile a task handoff, e.g. make agent_workflow_handoff TASK=TASK-123 CONTEXT="Current progress"
agent_workflow_handoff:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_handoff TASK=TASK-123 CONTEXT=\"...\""; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow handoff "$(TASK)" $(if $(CONTEXT_FILE),--context-file "$(CONTEXT_FILE)",--context $(call AGENT_LOOP_QUOTE,$(if $(CONTEXT),$(CONTEXT),$(TASK) handoff))) $(ARGS),agent_workflow_handoff,)

.PHONY: agent_workflow_close ## close a task session, e.g. make agent_workflow_close TASK=TASK-123 [STATUS=done]
agent_workflow_close:
	@test -n "$(TASK)" || { echo "TASK is required, e.g. make agent_workflow_close TASK=TASK-123 [STATUS=done]"; exit 1; }
	$(call AGENT_LOOP_RUN,$(AGENT_LOOP_BIN) workflow close "$(TASK)" --status "$(if $(STATUS),$(STATUS),done)" $(ARGS),agent_workflow_close,)
