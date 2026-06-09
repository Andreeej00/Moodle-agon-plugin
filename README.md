# 🎮 Agon — Gamified Learning for Moodle

> **`mod_agon`** · A Moodle activity that turns a week's course topic into a short, competitive run of mini-games — so students prep for quizzes by *playing*.

> **Status: Phase 2 — server-authoritative backend (in progress).** The full run plays inside Moodle, and scoring, attempts, hints and the course leaderboard are now **real and server-side** — answers never reach the browser, one attempt and one hint per game are enforced. Still pending: gradebook export and server-enforced timers + per-attempt randomization.

## 📸 Screens

| Opening | Crossword | Weekly question |
| --- | --- | --- |
| ![Opening](docs/img/opening.png) | ![Crossword](docs/img/crossword.png) | ![Weekly question](docs/img/weekly_question.png) |

| Coding | Completion | Teacher config |
| --- | --- | --- |
| ![Coding](docs/img/coding.png) | ![Completion](docs/img/completion.png) | ![Teacher config](docs/img/config.png) |

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

## 🎮 Play experience

A **Start gate + countdown** on the timed games (the question/code stay hidden until you press Start), smooth **screen transitions**, **per-game hints** (reveal a crossword letter, show the question's explanation, or cue the next code blank), **instant feedback** on every screen (what you got right/wrong, with an animated count-up final score), and a **tap-friendly** UI for phones.

## 👤 Two views

- **Student** — plays the run: **Start → Crossword → Weekly question → Coding → Score** (bottom-nav stepper).
- **Professor / assistant** — **doesn't play**: **configures** the activity (picks games + pastes each game's **JSON** content) and **monitors** (student attempts + leaderboard).

## 🛡️ Anti-AI by design

Real grade points are on the line, so the goal is **honest play faster than cheating** (not "AI-proof"). Implemented in the UI:

- **Start gate + countdown** — the question (10s) and coding (45s) stay hidden behind a Start button; the content reveals and the clock starts only when the student commits, so they can't pre-read or screenshot before the timer. Time-up auto-submits.
- **Weekly question** — the options are **blurred** (tap/hover to read one), so the whole set can't be screenshotted at once.
- **Coding** — revealed **one line at a time**, and revealing the next line **locks the lines above** — no reveal-everything-then-screenshot.
- **Crossword** — deliberately the low-stakes, shareable warm-up.

Still server-side (Phase 2): authoritative scoring, server-enforced timers, per-attempt randomization. *(Today the timer + scoring run client-side.)*

## 🧩 Architecture (short)

**One engine + pluggable games** — a shared core (content, scoring, timing, leaderboard, gradebook) with each game as a swappable "renderer." Teachers choose which games an activity includes. In Moodle it renders via Mustache templates + plain JS, styled by a scoped `styles.css`.

→ Full design: **[plan.md](plan.md)** · Architecture: **[docs/architecture.md](docs/architecture.md)** · Setup & handover: **[HANDOVER.md](HANDOVER.md)**

## 🗺️ Roadmap

- **Phase 0 — Setup:** ✅ moodle-docker, `mod_agon` scaffolded, installed, live.
- **Phase 1 — Playable in Moodle:** ✅ student game + teacher config/monitor render in Moodle; activity config (games + JSON content) saved; play is content-driven. ← *here*
- **Phase 2 — Real backend:** server-side scoring, attempt tracking, the live course leaderboard, gradebook, capabilities, enforced timers + randomization.
- **Phase 3 — Full experience:** daily challenge + streaks, glossary / question-bank import, transitions/animations, accessibility, touch support, dark mode.

## 🚀 Getting started

**Run it in Moodle (dev):** with [moodle-docker](https://github.com/moodlehq/moodle-docker), place this folder at `<moodle>/mod/agon` and install from the admin *Notifications* page. Full setup, test accounts, and gotchas are in **[HANDOVER.md](HANDOVER.md)**.

**Install on another Moodle:** zip the plugin with a single top folder named `agon` and upload via *Site administration → Plugins → Install plugins* (built on **Moodle 4.5 LTS**).

**Static design mock (no Moodle):** open [`prototype/index.html`](prototype/index.html) in a browser.

## 📁 Repository layout

This repo **is** the `mod_agon` plugin (installs to `mod/agon`):
- plugin source at the root — `version.php`, `lib.php`, `mod_form.php`, `view.php`, `db/`, `classes/`, `lang/`, `pix/`, `styles.css`, `templates/`, `js/`, `tests/`
- `prototype/` — standalone clickable UI mock (design only; not shipped to Moodle)
- `plan.md`, `docs/`, `HANDOVER.md` — plan, architecture, and handover notes

## 📜 License

GNU GPL v3 or later — like Moodle itself. See [LICENSE.md](LICENSE.md).

## 🎓 Credits

A university project. Built on Moodle; scaffolded with `tool_pluginskel`; design & code assisted with Claude Code.
