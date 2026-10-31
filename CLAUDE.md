# CLAUDE.md

**Read [`AGENTS.md`](AGENTS.md) in this directory first.** It is the single
source of truth for this project: what it is (an open-source browser security
assessment tool for red teams), the file map, the request lifecycle,
the hard rules and gotchas, the graphify knowledge graph, and the change log.

Before editing: skim AGENTS.md §2 (file map) and §4 (hard rules). Before
searching the tree for how something connects, query the graph (AGENTS.md §5).

This repo runs under the **ponytail** discipline (write the smallest complete
change) — installed at `.claude/skills/ponytail/SKILL.md`, summarized in
AGENTS.md §8. Its one hard line and ours: never cut security, validation,
or accessibility in the name of "less code".
