# 🎮 Agon — Gamified Learning for Moodle

> **`mod_agon`** · A Moodle activity that turns a week's course topic into a short, competitive run of mini-games — so students prep for quizzes by *playing*.

> ⚠️ **Status: Phase 1 — UI skeleton.** The plugin installs and runs in Moodle (Phase 0 done); a clickable mock of every screen lives in [`prototype/`](prototype/index.html). Game logic and scoring are next. See the [roadmap](#️-roadmap).

<!-- ![Agon gameplay](docs/img/hero.gif) -->

## ✨ What is Agon?

Agon (Greek for *contest*) is a generic, reusable Moodle activity module. A teacher drops it into any course, configures a week's content, and students get a fun, competitive way to revise — with one **course-wide leaderboard** and **extra grade points** for top performers. Designed to work for **any subject**.

## 🪜 The learning ladder

A student plays a linear run on the week's topic — each game asks them to do **more** with it (Bloom's *recall → understand → apply*):

| Step | Game | Trains | Scoring |
| --- | --- | --- | --- |
| 1 | **Crossword** | Recall | finish-rank: 1st–3rd = **1.0**, 4th–10th = **0.75**, rest = **0.5** · no timer |
| 2 | **Weekly Question** | Understand | timed · correct = **1.0**, wrong = **0** |
| 3 | **Coding** | Apply | timed · 2 sequences × **0.5**, partial credit per correct placement |

**One hint** and **one attempt**; all points **sum into a single course-wide leaderboard**. On finishing, the student sees today's score + a leaderboard preview.

## 👤 Two views

- **Student** — plays the run (Start → Crossword → Question → Reveal → Coding → Review → Score), with a bottom-nav stepper.
- **Professor / assistant** — **doesn't play**: **configures** the activity (picks games + pastes each game's **JSON** content) and **monitors** (student attempts + leaderboard).

## 🛡️ Designed to be fair

Real grade points are on the line, so Agon makes **honest play faster than cheating** (not "AI-proof"): **tight timers** (question + coding), **per-attempt randomization**, and **click/drag interaction** that doesn't copy-paste. The crossword is the low-stakes, shareable warm-up.

## 🧩 Built to be generic & extensible

Under the hood: **one engine + pluggable games** — a shared core (content, scoring, timing, leaderboard, gradebook) with each game a swappable "renderer." Teachers choose which games an activity includes.

→ Full design: **[plan.md](plan.md)** · Architecture: **[docs/architecture.md](docs/architecture.md)**

## 🗺️ Roadmap

- **Phase 0 — Setup:** ✅ moodle-docker, `mod_agon` scaffolded, installed, live.
- **Phase 1 — UI + engine:** ✅ UI skeleton (`prototype/`). Next: data model, engine + game contract, JSON content, server-side scoring, gradebook, course leaderboard. ← *here*
- **Phase 2 — Competitive layer:** rewards, capabilities, anti-cheat levers.
- **Phase 3 — Full experience:** daily challenge + streaks, glossary / question-bank import, polish.

## 🚀 Getting started

**Preview the UI** (no install): open [`prototype/index.html`](prototype/index.html) in a browser — try the **Student** run and the **Professor** view.

**Run in Moodle** (dev): with [moodle-docker](https://github.com/moodlehq/moodle-docker), place this folder at `<moodle>/mod/agon` and install from the admin *Notifications* page. To install on another Moodle, zip the plugin with a top folder named `agon` and upload via *Site administration → Plugins → Install plugins* (built on Moodle 4.5 LTS).

## 📁 Repository layout

This repo **is** the `mod_agon` plugin (installs to `mod/agon`):
- plugin source at the root — `version.php`, `lib.php`, `mod_form.php`, `view.php`, `db/`, `classes/`, `lang/`, `pix/`, `tests/`
- `prototype/` — standalone clickable UI mock (design only; not shipped to Moodle)
- `plan.md`, `docs/` — plan & architecture notes

## 📜 License

GNU GPL v3 or later — like Moodle itself. See [LICENSE.md](LICENSE.md).

## 🎓 Credits

A university project. Built on Moodle; scaffolded with `tool_pluginskel`; design & code assisted with Claude Code.
