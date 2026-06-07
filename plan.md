# Moodle "agon" — Project Plan

*A competitive, gamified learning plugin for Moodle. Living document — last updated 2026-06-07.*

## 1. Vision
A Moodle activity that helps students prep for quizzes by turning a week's course topic into a short, fun, competitive run of mini-games. Generic across courses. One course-wide leaderboard visible to everyone; top performers earn extra grade points. Modern, smooth, a little addictive.

## 2. Plugin shape
- **Type:** activity module `mod_agon` (`/mod/agon/`) — gives gradebook integration, capabilities, backup/restore, per-attempt tracking.
- **Architecture:** one shared **engine** (content, attempts, scoring, timing, leaderboard, gradebook) + pluggable **game renderers** on top. Like an MVVM ViewModel with swappable views. Adding a game = adding a renderer, not a new plugin.

## 3. Content model — JSON per game, entered at setup
The professor chooses which games the activity includes, then pastes each chosen game's content as **JSON** (per week/topic). Schemas:
- **Crossword:** `{ subject, subject_code, week, topic, language, difficulty, author, words:[{ number, word, clue, direction, row, col }] }`
- **Questions (~5/week):** `{ subject_code, week, topic, questions:[{ question, options:[…], correct:index, explanation }] }`
- **Coding (2 sequences):** `{ subject_code, week, sequences:[{ title, code (with ____), blanks:[…], options:[…] }] }`

*(Later: import from Glossary / Question Bank.)*

## 4. Game lineup — a learning ladder (recall → understand → apply)
A linear run on the week's topic; every game's points sum into one course leaderboard.

1. **Crossword — "recall."** Fill the grid from clues. **No timer.** Scored by finish-rank: **first 3 = 1.0, 4th–10th = 0.75, everyone else = 0.5.** (Shared grid — accepted as shareable.)
2. **Weekly Question — "understand."** A **timed** multiple-choice question → confirm → the correct answer is revealed. **Correct = 1.0, wrong = 0.**
3. **Coding — "apply."** **Timed.** **Two code sequences** (each ≥3 blanks, ≥5 options); click/drag an option into each blank. Each sequence worth **0.5**, **partial credit per correct placement** (4/5 → 0.4).

Across the attempt: **one hint**, **one attempt** (no unlimited practice). Timers on the question and coding only.

## 5. Roles, flow & competitive layer
- **Student** plays the linear run, with an inner bottom-nav stepper:
  Start → Crossword → *(Next)* → Question → *(Confirm)* → Reveal answer → Coding → Review correct sequence → **Today's score + leaderboard preview**.
- **Professor / assistant** **does not play** — they **configure** (pick games + paste JSON) and **monitor** (student attempts table + the leaderboard).
- **One course-wide leaderboard**; points **sum cumulatively** across games and weeks.
- **Rewards:** top performers get extra grade points (gradebook wiring + reward rule in a later phase).
- **Privacy:** leaderboard shows names → setting + Moodle Privacy API.

## 6. Anti-cheat / AI-resistance
Goal: make **honest play faster than cheating** (not "AI-proof"). Server-enforced levers: **tight timers** (question + coding), **per-attempt randomization**, and **interaction that doesn't copy-paste** (drag/click placement). The crossword is deliberately the low-stakes, shareable warm-up.

## 7. Testing & dev environment
- **moodle-docker** runs Moodle 4.5 LTS; the plugin lives at `mod/agon`. Mock data via `admin/tool/generator`.
- **Quality:** Moodle coding style + `moodle-plugin-ci`; PHPUnit on the engine; Behat for flows.

## 8. Phased roadmap
- **Phase 0 — Environment. ✅ Done.** moodle-docker + Moodle 4.5; `mod_agon` scaffolded, installed, live.
- **Phase 1 — UI + engine (in progress).** ✅ Clickable **UI skeleton** (`prototype/`: student run + professor config/monitor). Next: data model (`db/install.xml`), engine + game contract, JSON content loading, server-authoritative scoring, gradebook, course leaderboard. *(Wire one game's full loop first to de-risk.)*
- **Phase 2 — Competitive layer.** Reward rules, capabilities, anti-cheat levers, polish.
- **Phase 3 — Extras.** Daily challenge + streaks, glossary/question-bank import, animations, responsive + dark, accessibility.

## 9. Notes & open questions
- `prototype/` is a standalone HTML/CSS/JS mock of every screen — design only, ported into Moodle Mustache/AMD as functionality is wired. Open `prototype/index.html`.
- Content and UI are **English only** (per requirement). Palette: dark teal + green.
- Open: gradebook ↔ leaderboard mapping; exact reward mechanism for top performers; backup/restore; front-end build (AMD + Mustache); crossword placement validation in the config tool.
