AGENT_MAP ?= vendor/bin/agent-map
AGENT_MAP_INDEX ?=
AGENT_MAP_INDEX_ARG = $(if $(AGENT_MAP_INDEX),--index=$(AGENT_MAP_INDEX))
AGENT_MAP_OUT_ARG = $(if $(AGENT_MAP_INDEX),--out=$(AGENT_MAP_INDEX))
AGENT_MAP_PATHS ?= src,tests
AGENT_MAP_BASE ?= main
AGENT_MAP_FORMAT ?= text
AGENT_MAP_STORAGE_FORMAT ?= json
AGENT_MAP_LIMIT ?= 20
AGENT_MAP_SYMBOL_LIMIT ?= 10
AGENT_MAP_METHOD_LIMIT ?= 10

AGENT_MAP_READ_OPTIONS = --format=$(if $(format),$(format),$(AGENT_MAP_FORMAT)) --limit=$(if $(limit),$(limit),$(AGENT_MAP_LIMIT)) --symbol-limit=$(if $(symbol_limit),$(symbol_limit),$(AGENT_MAP_SYMBOL_LIMIT)) --method-limit=$(if $(method_limit),$(method_limit),$(AGENT_MAP_METHOD_LIMIT))

.PHONY: ai-map-build
ai-map-build:
	$(AGENT_MAP) build \
		--root=. \
		--paths=$(AGENT_MAP_PATHS) \
		$(AGENT_MAP_OUT_ARG) \
		--format=$(AGENT_MAP_STORAGE_FORMAT) \
		--exclude='~(^|/)vendor(/|$$)~' \
		--exclude='~(^|/)var/cache(/|$$)~'

.PHONY: ai-map-refresh
ai-map-refresh:
	$(AGENT_MAP) refresh \
		--root=. \
		$(AGENT_MAP_INDEX_ARG) \
		$(AGENT_MAP_OUT_ARG) \
		--format=$(AGENT_MAP_STORAGE_FORMAT)

.PHONY: ai-map-stale
ai-map-stale:
	$(AGENT_MAP) stale $(AGENT_MAP_INDEX_ARG)

.PHONY: ai-map-summary
ai-map-summary:
	$(AGENT_MAP) summary $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-query
ai-map-query:
	@test -n "$(q)" || (echo "Usage: make ai-map-query q=EvidenceValidator" && exit 2)
	$(AGENT_MAP) query "$(q)" $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-file
ai-map-file:
	@test -n "$(f)" || (echo "Usage: make ai-map-file f=src/Foo.php" && exit 2)
	$(AGENT_MAP) file "$(f)" $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-changed
ai-map-changed:
	$(AGENT_MAP) changed $(AGENT_MAP_INDEX_ARG) --base=$(if $(base),$(base),$(AGENT_MAP_BASE)) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-related
ai-map-related:
	@test -n "$(q)" || (echo "Usage: make ai-map-related q=EvidenceValidator" && exit 2)
	$(AGENT_MAP) related "$(q)" $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-stats
ai-map-stats:
	$(AGENT_MAP) stats $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-callers
ai-map-callers:
	@test -n "$(q)" || (echo "Usage: make ai-map-callers q='App\\Foo::bar'" && exit 2)
	$(AGENT_MAP) callers "$(q)" $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-callees
ai-map-callees:
	@test -n "$(q)" || (echo "Usage: make ai-map-callees q='App\\Foo::bar'" && exit 2)
	$(AGENT_MAP) callees "$(q)" $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)

.PHONY: ai-map-context
ai-map-context:
	@test -n "$(q)" || (echo "Usage: make ai-map-context q='App\\Foo::bar'" && exit 2)
	$(AGENT_MAP) context "$(q)" $(AGENT_MAP_INDEX_ARG) $(AGENT_MAP_READ_OPTIONS)
