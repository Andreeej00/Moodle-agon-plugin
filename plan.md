# Moodle "agon" — Project Plan

*A competitive, gamified learning plugin for Moodle. Living document — last updated 2026-06-08.*

## 0. Where the project is right now
**Phase 1 is playable and polished in Moodle.** A teacher configures games + content in the activity settings; a student plays the full run (crossword → 10s question → 45s coding → score), with a Start gate + countdown on the timed games, per-game hints, instant correct/incorrect feedback on each screen, screen transitions, and tap support. **Still placeholder / not yet real (Phase 2):** scoring, attempt tracking, the live leaderboard, gradebook grades, and *server-enforced* timers/scoring — today the timer + grading run client-side. See §8.

## 1. Vision
A Moodle activity that helps students prep for quizzes by turning a week's course topic into a short, fun, competitive run of mini-games. Generic across courses. One course-wide leaderboard visible to everyone; top performers earn extra grade points. Modern, smooth, a little addictive.

## 2. Plugin shape
- **Type:** activity module `mod_agon` (`/mod/agon/`) — gives gradebook integration, capabilities, backup/restore, per-attempt tracking.
- **Architecture:** one shared **engine** (content, attempts, scoring, timing, leaderboard, gradebook) + pluggable **game renderers** on top. Like an MVVM ViewModel with swappable views. Adding a game = adding a renderer, not a new plugin.

## 3. Content model — JSON per game, entered at setup
The professor chooses which games the activity includes (3 checkboxes), then pastes each chosen game's content as **JSON** (per week/topic). Stored in the `agon` table columns `content{crossword,question,coding}`; toggles in `game{crossword,question,coding}`. Schemas:
- **Crossword:** `{ subject, subject_code, week, topic, language, difficulty, author, words:[{ number, word, clue, direction, row, col }] }`
- **Questions (~5/week):** `{ subject_code, week, topic, questions:[{ question, options:[…], correct:index, explanation }] }`
- **Coding (2 sequences):** `{ subject_code, week, sequences:[{ title, code (with ____), blanks:[…], options:[…] }] }`

*(Later: import from Glossary / Question Bank.)*

## 4. Game lineup — a learning ladder (recall → understand → apply)
A linear run on the week's topic; every game's points sum into one course leaderboard.

1. **Crossword — "recall."** Fill the grid from clues. **No timer.** Scored by finish-rank: **first 3 = 1.0, 4th–10th = 0.75, everyone else = 0.5.**
2. **Weekly Question — "understand."** Behind a **Start gate**: press Start → the question reveals and a **10-second** clock runs; the **options are blurred** (tap/hover to read). **Correct = 1.0, wrong = 0**, with instant feedback.
3. **Coding — "apply."** Behind a **Start gate** with a **45-second** clock. **Two code sequences** revealed **one line at a time**; each revealed line is drag/tap-droppable and revealing the next **locks** the previous lines. Each sequence **0.5**, **partial per correct placement**.

Across the attempt: **one hint**, **one attempt**. Timers shown on question + coding.

## 5. Roles & flow
- **Student** plays the linear run with a bottom-nav stepper: **Start → Crossword → Question → Coding → Today's score + leaderboard preview**.
- **Professor / assistant** **does not play** — they **configure** (pick games + paste JSON in the activity settings) and **monitor** (student attempts table + the leaderboard). Branch is on the `moodle/course:manageactivities` capability.
- **One course-wide leaderboard**; points **sum cumulatively** across games and weeks.
- **Rewards:** top performers get extra grade points (gradebook wiring is a later phase).

## 6. Anti-cheat / AI-resistance
Goal: **honest play faster than cheating**. **Implemented (UI):** a **Start gate** that hides the question/code until the student commits, then a **countdown** (10s / 45s, auto-submit on time-up); blurred question options; progressive coding reveal with previous-line locking; crossword is the shareable low-stakes warm-up. **Still server-side (Phase 2):** authoritative scoring, server-enforced timers, per-attempt randomization — today the timer + grading are client-side.

## 7. Testing & dev environment
- **moodle-docker** runs Moodle 4.5 LTS; the plugin lives at `mod/agon`. See `HANDOVER.md` for the exact setup, test accounts, and gotchas.
- **Quality (planned):** Moodle coding style + `moodle-plugin-ci`; PHPUnit on the engine; Behat for flows.

## 8. Phased roadmap
- **Phase 0 — Environment. ✅ Done.** moodle-docker + Moodle 4.5; `mod_agon` scaffolded, installed, live.
- **Phase 1 — Playable in Moodle. ✅ Done.** `view.php` renders the student game (Mustache + plain JS) vs a teacher monitor; activity config (game toggles + per-game JSON with guidelines + examples) saved to DB columns; play is content-driven (example fallback); flow respects the enabled games; UI anti-cheat (blur + progressive reveal/lock).
- **Phase 2 — Real backend (next).** DB tables for attempts/responses; server-side scoring; attempt lifecycle; the real course leaderboard; gradebook grades; `db/access.php` capabilities; privacy provider update (we will store personal data); enforced timers + randomization; one-attempt enforcement.
- **Phase 3 — Full experience.** Daily challenge + streaks; glossary/question-bank import; screen transitions + micro-animations; accessibility + touch support; dark mode; crossword placement validation; backup/restore.

## 9. Notes & open questions
- The play view currently renders from the saved JSON with **placeholder** leaderboard/attempt data (real data comes in Phase 2).
- Content and UI are **English only**. Palette: dark teal + green.
- `prototype/` is a standalone HTML/CSS/JS mock; the real UI now lives in `templates/` + `js/` + `styles.css`.
- Open: gradebook ↔ leaderboard mapping; exact reward mechanism for top performers; touch/drag support (HTML5 DnD + hover don't work on touch); whether to move the front-end to AMD; crossword placement validation in the config tool.
