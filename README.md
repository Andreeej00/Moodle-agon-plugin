# 🎮 Agon — Gamified Learning for Moodle

> **`mod_agon`** · A Moodle activity that turns course terms into a short, competitive set of minigames — so students prep for quizzes by *playing*.

> ⚠️ **Status: early development (Phase 0 — project setup).** This repository currently holds the project's design and documentation; the plugin itself is being built. See the [roadmap](#️-roadmap).

<!-- ![Agon gameplay](docs/img/hero.gif) -->

## ✨ What is Agon?

Agon (Greek for *contest*) is a generic, reusable Moodle activity module. A teacher drops it into any course, adds a set of terms, and students get a fun, competitive way to revise — with **leaderboards** visible to students, teachers, and assistants, and **extra grade points** for top performers.

It's designed to work for **any subject**, not just one course.

## 🪜 The learning ladder

Instead of one game, Agon walks a student up a ladder on the *same* concept — each step asks them to do **more** with it (mirroring the lower rungs of Bloom's taxonomy: *recall → understand → apply*):

| Step | Game | What it trains |
| --- | --- | --- |
| 1 | **Crossword** | Recall the term |
| 2 | **Reveal & Answer** | Understand it (timed; one box open at a time) |
| 3 | **Code Blocks** | Apply it (timed; fill the gaps from one-at-a-time menus) |

One concept, climbed — not three disconnected games.

## 🛡️ Designed to be fair

Because real grade points are on the line, Agon aims to make **honest play faster than cheating** (not "AI-proof" — that's impossible — just not worth the effort). Four server-enforced levers:

- **Tight timers** — no time to round-trip an answer to an AI.
- **Per-attempt randomization** — a shared answer goes stale.
- **Progressive disclosure** — only one piece is revealed at a time, fetched on demand, so the full answer never sits on the page.
- **Hands-on interaction** — dragging / selecting / spatial input that doesn't copy-paste.

## 🧩 Built to be generic & extensible

Under the hood, Agon is **one engine + pluggable games**: a shared core (content, scoring, timing, leaderboard, gradebook) with each game as a swappable "renderer" on top. Adding a new game means writing a new renderer — not a new plugin. Teachers choose which games are active per activity.

→ Full design: **[plan.md](plan.md)** · Technical architecture: **[docs/architecture.md](docs/architecture.md)**

## 🗺️ Roadmap

- **Phase 0 — Setup:** dev environment (moodle-docker), scaffold `mod_agon`, design docs. ← *here*
- **Phase 1 — MVP:** teacher content authoring → one game end-to-end → server-side scoring → gradebook → basic leaderboard.
- **Phase 2 — Competitive layer:** per-course leaderboard, capabilities, rewards, anti-cheat levers.
- **Phase 3 — Full experience:** all three games, daily challenge + streaks, glossary / question-bank import, polish (animations, responsive, accessibility).

## 🚀 Getting started (development)

> The plugin is not yet installable — this is the planned workflow.

```sh
# Run Moodle locally with moodle-docker, then place this plugin at:
#   <moodle>/mod/agon
# and complete the install from the Moodle admin "Notifications" page.
```

## 📁 Repository layout

- `plan.md` — project plan & design decisions
- `docs/` — architecture notes and screenshots
- *(plugin source will live at the repository root: `version.php`, `lib.php`, `classes/`, `amd/`, `templates/`, `db/`, `lang/`, `tests/` …)*

> Note: this repo currently also contains [`tool_pluginskel`](https://moodle.org/plugins/tool_pluginskel) — the Moodle skeleton **generator** used to scaffold the plugin. It's kept here for reference and will be trimmed as the real plugin takes shape.

## 📜 License

GNU GPL v3 or later — like Moodle itself. See [LICENSE](LICENSE).

## 🎓 Credits

A university project. Built on Moodle; scaffolded with `tool_pluginskel`; design & code assisted with Claude Code.
