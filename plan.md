# Moodle "agon" — Project Plan

*A competitive, gamified learning plugin for Moodle. Living document — last updated 2026-06-11.*

## 0. Where the project is right now
**Phase 2 backend is mostly in place.** A teacher configures games + content; a student plays the full run (crossword → 10s question → 45s coding → score) with the **scoring, attempts, hints and the cumulative course leaderboard all server-side** — answers never reach the browser (the answer-split), one attempt + one hint per game are enforced, and the front end is an **AMD module** talking to web services. The UI was reskinned to a Moodle-blue theme and the teacher monitor gained search + a state filter. **Still pending (see §8):** gradebook export and *server-enforced* timers + per-attempt randomization — today the countdown is client-side display only.

## 1. Vision
A Moodle activity that helps students prep for quizzes by turning a week's course topic into a short, fun, competitive run of mini-games. Generic across courses. One course-wide leaderboard visible to everyone; top performers earn extra grade points. Modern, smooth, a little addictive.

## 2. Plugin shape
- **Type:** activity module `mod_agon` (`/mod/agon/`) — gives gradebook integration, capabilities, backup/restore, per-attempt tracking.
- **Architecture:** one shared **engine** (content, attempts, scoring, timing, leaderboard, gradebook) + pluggable **game renderers** on top. Like an MVVM ViewModel with swappable views. Adding a game = adding a renderer, not a new plugin.

## 3. Content model — JSON per game, entered at setup
The professor chooses which games the activity includes (3 checkboxes), then pastes each chosen game's content as **JSON** (per week/topic). Stored in the `agon` table columns `content{crossword,question,coding}`; toggles in `game{crossword,question,coding}`. Schemas:
- **Crossword:** `{ subject, subject_code, week, topic, language, difficulty, author, words:[{ number, word, clue, direction, row, col }] }`
- **Questions (~5/week):** `{ subject_code, week, topic, questions:[{ question, options:[…], correct:index, explanation }] }` — a **pool**: each run serves **one** question from it (currently the first; per-attempt randomization picks one in step 9).
- **Coding (2 sequences):** `{ subject_code, week, sequences:[{ title, code (with ____), blanks:[…], options:[…] }] }`

*(Later: import from Glossary / Question Bank.)*

## 4. Game lineup — a learning ladder (recall → understand → apply)
A linear run on the week's topic; every game's points sum into one course leaderboard.

1. **Crossword — "recall."** Fill the grid from clues. **No timer.** **Fully solved** → finish-rank among full solvers: **first 3 = 1.0, 4th–10th = 0.75, every later full solve = 0.5.** **Partially solved** → fraction-correct × 0.5, **capped at 0.49**, so a partial can never reach (or beat) a full solver's 0.5.
2. **Weekly Question — "understand."** Behind a **Start gate**: press Start → the question reveals and a **10-second** clock runs; the **options are blurred** (tap/hover to read). **Correct = 1.0, wrong = 0**, with instant feedback.
3. **Coding — "apply."** Behind a **Start gate** with a **45-second** clock. **Two code sequences** revealed **one line at a time**; each revealed line is drag/tap-droppable and revealing the next **locks** the previous lines. Each sequence **0.5**, **partial per correct placement**.

Across the attempt: **one hint**, **one attempt**. Timers shown on question + coding.

## 5. Roles & flow
- **Student** plays the linear run with a bottom-nav stepper: **Start → Crossword → Question → Coding → Today's score + leaderboard preview**.
- **Professor / assistant** **does not play** — they **configure** (pick games + paste JSON in the activity settings) and **monitor** (student attempts table + the leaderboard). Branch is on the `moodle/course:manageactivities` capability.
- **One course-wide leaderboard**; points **sum cumulatively** across games and weeks.
- **Rewards:** top performers get extra grade points (gradebook wiring is a later phase).

## 6. Anti-cheat / AI-resistance
Goal: **honest play faster than cheating**. **Implemented (UI):** a **Start gate** that hides the question/code until the student commits, then a **countdown** (10s / 45s, auto-submit on time-up); blurred question options; progressive coding reveal with previous-line locking; crossword is the shareable low-stakes warm-up. **Now server-side:** authoritative scoring + the **answer-split** (answers stay on the server, never in `window.AGON`; each game's answers + explanation are revealed only *after* it is submitted); **one attempt + one hint per game** enforced. **Still to do (step 9):** server-enforced timers and per-attempt randomization — today the countdown is client-side display only.

## 7. Testing & dev environment
- **moodle-docker** runs Moodle 4.5 LTS; the plugin lives at `mod/agon`. See `README.md` for the exact setup, test accounts, and gotchas.
- **Quality (planned):** Moodle coding style + `moodle-plugin-ci`; PHPUnit on the engine; Behat for flows.

## 8. Phased roadmap
- **Phase 0 — Environment. ✅ Done.** moodle-docker + Moodle 4.5; `mod_agon` scaffolded, installed, live.
- **Phase 1 — Playable in Moodle. ✅ Done.** `view.php` renders the student game (Mustache + plain JS) vs a teacher monitor; activity config (game toggles + per-game JSON with guidelines + examples) saved to DB columns; play is content-driven (example fallback); flow respects the enabled games; UI anti-cheat (blur + progressive reveal/lock).
- **Phase 2 — Real backend (mostly done).** Scoring is server-authoritative and the leaderboard is real. Status per step:
  1. ✅ **Data model.** `agon_attempt` (one row per run: `agonid, userid, timestart, timefinish, state, score` + per-game `scorecrossword/scorequestion/scorecoding`, plus `submittedgames` + `hintsused` JSON markers). Via `db/install.xml` + `db/upgrade.php` + `version.php` bumps.
  2. ✅ **Scoring engine (server).** `mod_agon\local\scoring` owns the §4 rules; `mod_agon\local\attempt` runs the lifecycle (start / submit_game / finish). No grading in the browser.
  3. ✅ **Answer split.** `mod_agon\local\content` ships only *renderable* content to `window.AGON` (clues, options, code-with-blanks) — never the `correct` index or coding `blanks`. Reveal-on-submit feedback returns the answers after each game.
  4. ✅ **Comms layer — AMD + web services.** `amd/src/player.js` (module `mod_agon/player`) calls external services in `db/services.php` + `classes/external/`: `start_attempt`, `submit_game`, `finish_attempt`, `get_hint`, `get_leaderboard`. One attempt + one hint per game enforced server-side.
  5. ✅ **Real leaderboard.** `mod_agon\local\leaderboard` sums each student's scores across all agon instances in the course; the student results screen fetches it live, the teacher monitor renders it server-side.
  6. ⏳ **Gradebook.** Add the missing `grade` column to the `agon` table (the `lib.php` scaffold referenced it but it was never created — the scale stubs and the grading section of the settings form return then), wire `agon_update_grades` so the top-performer reward (§4/§5) lands in the gradebook.
  7. ◑ **Capabilities.** ✅ `db/access.php` (`mod/agon:addinstance/:play/:manage/:viewleaderboard`); the web services authorize on them. **Pending:** switch the `view.php` teacher branch from `moodle/course:manageactivities` to `mod/agon:manage`.
  8. ✅ **Privacy provider.** Real `mod_agon\privacy\provider` (metadata + export + delete + userlist) replacing the `null_provider`.
  9. ⏳ **Enforced timers + randomization.** Server compares its `timestart` against submit time (client countdown becomes display-only); per-attempt question selection from the pool. Also fix two known (rare) races here: wrap the crossword finish-rank count in a transaction/lock (two simultaneous full solves can currently claim the same rank), and make `attempt::start` collision-safe (two parallel first calls can hit the unique key — catch and re-fetch instead of erroring).
  10. ◑ **Tests.** ✅ Real PHPUnit across 6 files (`scoring`, `attempt`, `content`, `leaderboard`, `external/services`, `privacy/provider`). **Pending:** Behat for the play flow + `moodle-plugin-ci` in CI.
- **Phase 3 — Full experience.** Daily challenge + streaks; glossary/question-bank import; screen transitions + micro-animations; accessibility + touch support; dark mode; crossword placement validation; backup/restore. *(Already landed early: a Moodle-blue visual theme, a searchable/filterable teacher monitor, and a branded puzzle-cube activity icon.)*

## 9. Notes & open questions
- Leaderboard + attempt data are now **real** (server-side); the only placeholder left is the gradebook grade (step 6).
- Content and UI are **English only**. Palette: **Moodle-blue azure** (`--accent #1574cf`) over spray-white/grey cards, with **gold reserved** for the leaderboard medals + hint cues; the old teal/green was retired so the activity blends with Moodle's white page. Correct/wrong stay green/red.
- The activity icon (`pix/monologo.svg`) is a **branded** (own-colours, no purpose tile) black-and-white isometric AGON puzzle cube.
- `prototype/` is a standalone HTML/CSS/JS mock; the real UI lives in `templates/` + `amd/` + `js/` + `styles.css`.
- **Decided (2026-06-09):** Phase 2 started with the data model + server scoring engine; the front end moved to **AMD + external web services** (resolves the earlier "move to AMD?" question).
- Open: gradebook ↔ leaderboard mapping; exact reward mechanism for top performers; touch/drag support (HTML5 DnD + hover don't work on touch); a "not started" filter on the monitor (needs the enrolled-student list); crossword placement validation in the config tool.
