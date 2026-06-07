# Architecture

Technical companion to [plan.md](../plan.md). This describes *how* `mod_agon` is built; `plan.md` covers *what* and *why*.

## Plugin type

`mod_agon` is a Moodle **activity module** (`/mod/agon/`) — giving a per-instance DB table + settings form, **gradebook integration**, a **capability** system, **backup/restore**, and per-user attempt tracking.

## The engine + pluggable games

**One shared engine with swappable game "renderers"** — like an MVVM ViewModel (shared state) with interchangeable views.

- **Engine (shared):** content store, attempt & state tracking, scoring + timing, per-attempt randomization, leaderboard, gradebook writes.
- **Renderers (per game):** each game reads engine state and draws its own UI. Adding a game = adding a renderer + its scoring rule. No schema rewrite.

## Content model — JSON per game (entered at setup)

The professor enables games and pastes each game's content as JSON, per week/topic:

- **Crossword:** `{ subject, subject_code, week, topic, language, difficulty, author, words:[{ number, word, clue, direction, row, col }] }`
- **Questions:** `{ subject_code, week, topic, questions:[{ question, options:[…], correct:index, explanation }] }` (~5/week)
- **Coding:** `{ subject_code, week, sequences:[{ title, code (with ____), blanks:[…], options:[…] }] }` (2 sequences)

*(Later: importers from Glossary / Question Bank.)*

## Scoring & integrity

Scoring is **server-authoritative** — the browser is never trusted, because results convert to grades. The server issues the puzzle, starts the timer, and validates the solution.

**Rules:**
- **Crossword** — finish-rank: 1st–3rd = 1.0, 4th–10th = 0.75, rest = 0.5 (no timer).
- **Question** — correct = 1.0, wrong = 0 (timed).
- **Coding** — 2 sequences × 0.5, partial per correct placement (timed).
- One hint, one attempt. Points **sum into one course-wide leaderboard**.

### Anti-cheat levers (server-enforced)
**Tight timers** (question + coding) · **per-attempt randomization** · **click/drag interaction** that doesn't copy-paste. Crossword is the deliberate shareable, low-stakes exception.

## The three games

| Game | Rung | Mechanic | Timed |
| --- | --- | --- | --- |
| Crossword | Recall | Fill grid from clues (shared grid) | no |
| Weekly Question | Understand | Timed MCQ → confirm → reveal answer | yes |
| Coding | Apply | Two sequences; click/drag options into ≥3 blanks each | yes |

## Roles & views

- **Student** — plays the linear run (Start → Crossword → Question → Reveal → Coding → Review → Score + leaderboard preview) with an inner bottom-nav stepper.
- **Professor / assistant** — **does not play**; **configures** (choose games + paste JSON content) and **monitors** (student attempts table + course leaderboard). Gated by capabilities.

## UI prototype

A standalone clickable mock of every screen lives in [`../prototype/`](../prototype/index.html) (plain HTML/CSS/JS). Design-only — ported into Mustache/AMD as functionality is wired.

## Front-end & Moodle APIs (planned)

- **AMD modules** (`amd/src` → `amd/build`) for game logic; **Mustache templates** (`templates/`) for rendering.
- **External / web services** (`db/services.php` + `classes/external/`) for `start_attempt`, `submit`, and lazy "fetch next piece" calls.
- **Gradebook** callbacks in `lib.php`; **capabilities** in `db/access.php`; **backup/restore** under `backup/moodle2/`.

## Testing

- **moodle-docker** for a local Moodle + DB; **PHPUnit** on the engine; **Behat** for flows; **moodle-plugin-ci** in GitHub Actions once code exists.
