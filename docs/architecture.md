# Architecture

Technical companion to [plan.md](../plan.md). This describes *how* `mod_agon` is built; `plan.md` covers *what* and *why*.

## Plugin type

`mod_agon` is a Moodle **activity module** (`/mod/agon/`). That type gives, out of the box:

- a per-instance database table and settings form,
- **gradebook integration** (so leaderboard rewards become real grade points),
- a **capability** system (who can play / manage / see full results),
- **backup & restore** with the course,
- per-user attempt tracking.

## The engine + pluggable games

The core idea is **one shared engine with swappable game "renderers"** — conceptually like an MVVM ViewModel (shared state) with interchangeable views.

**Engine (shared):**
- content store (the term / clue / answer sets),
- attempt & state tracking,
- scoring + timing,
- per-attempt randomization,
- leaderboard,
- gradebook writes.

**Renderers (per game):** each game reads the same engine state and draws its own UI. A teacher enables the games they want per activity, so some renderers are universal (crossword, reveal-and-answer) and some are domain-specific (code blocks).

Adding a game = adding a renderer + its scoring rules. No new plugin, no schema rewrite.

## Content model

- **MVP:** teacher authors `term + clue/definition + answer` sets in the activity. Subject-agnostic.
- **Later:** importers from the Moodle **Glossary** (concept + definition) and **Question Bank**.

## Scoring & integrity

Scoring is **server-authoritative** — the browser is never trusted, because results convert to grades. The server issues the (randomized) puzzle, starts the timer, and validates the solution.

### Anti-cheat levers (server-enforced)

1. **Tight timers** — cheating round-trips don't fit the time budget.
2. **Per-attempt randomization** — shuffled options/values per player & attempt; shared answers are stale.
3. **Progressive disclosure** — the engine reveals one piece at a time and **fetches its content from the server on open**, so the full answer set never exists in the page DOM (defeats both screenshots and HTML inspection).
4. **Non-copy-pasteable interaction** — drag / select / spatial.

> The crossword is the exception: a shared grid can't be protected, so it's treated as a low-stakes warm-up with light leaderboard weight.

## The three games

| Game | Rung | Mechanic | Integrity |
| --- | --- | --- | --- |
| Crossword | Recall | Standard crossword grid | Shared; low weight |
| Reveal & Answer | Understand | Grid of boxes, **one open at a time**; answers fetched on open; timed | Progressive disclosure + timer |
| Code Blocks | Apply | Snippet split into blocks, each a **one-at-a-time** dropdown; options fetched on open; timed; language-agnostic | Progressive disclosure + timer (+ randomization) |

## Front-end & Moodle APIs (planned)

- **AMD modules** (`amd/src` → built to `amd/build`) for game logic.
- **Mustache templates** (`templates/`) for rendering.
- **External / web services** (`db/services.php` + `classes/external/`) for the on-demand "fetch next piece" and "submit attempt" calls.
- **Gradebook** callbacks in `lib.php`.
- **Capabilities** in `db/access.php`.
- **Backup / restore** under `backup/moodle2/`.

## Testing

- **moodle-docker** for a local Moodle + database.
- **PHPUnit** + **Behat** suites.
- **moodle-plugin-ci** in GitHub Actions for linting and tests (added once the plugin code exists).
