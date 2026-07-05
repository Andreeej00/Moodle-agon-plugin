# Moodle "agon" — Project Plan

*A competitive, gamified learning plugin for Moodle. Living document — last updated 2026-07-05 (release 0.3.1).*

## 0. Where the project is right now
**Playable end-to-end, authored end-to-end, and tested.** A teacher authors content in the **Question bank** tab (manual ⇄ JSON editors, a crossword builder with live preview, and optional **AI generation** from lecture material — paste / Google Docs link / PDF-PPTX upload); a student plays the full run (crossword → option-scaled timed question → lazy-loaded timed coding → score) with **scoring, attempts, the one-per-attempt hint and the cumulative course leaderboard all server-side** — answers never reach the browser (the answer-split), and coding sequences are **server-lazy-loaded one at a time** so the full set is never in the page. Crossword grading is teacher-selectable (finish-rank *custom* vs per-word *regular*). Quality: **PHPUnit (103 tests, ~95% line coverage of the engine + services), Behat flows, and Node tests for the crossword builder**. **Still pending (see §8):** gradebook export, backup/restore, server-enforced timers + per-attempt randomization — the countdown is client-side display only.

## 1. Vision
A Moodle activity that helps students prep for quizzes by turning a week's course topic into a short, fun, competitive run of mini-games. Generic across courses. One course-wide leaderboard visible to everyone; top performers earn extra grade points. Modern, smooth, a little addictive.

## 2. Plugin shape
- **Type:** activity module `mod_agon` (`/mod/agon/`) — gives gradebook integration, capabilities, backup/restore, per-attempt tracking.
- **Architecture:** one shared **engine** (content, attempts, scoring, timing, leaderboard, gradebook) + pluggable **game renderers** on top. Like an MVVM ViewModel with swappable views. Adding a game = adding a renderer, not a new plugin.

## 3. Content model — JSON per game, authored in the Question bank
The activity settings keep only the three game checkboxes; content is authored in the **Question bank** tab (manual editors, raw JSON, or AI generation) and validated on save (`content::validate_game`). Stored in the `agon` table columns `content{crossword,question,coding}`; toggles in `game{crossword,question,coding}`. Schemas:
- **Crossword:** `{ grading: "custom"|"regular", words:[{ number, word, clue, direction, row, col }] }` — the layout comes from the builder (`amd/src/crossword.js`); coordinates are validated non-negative.
- **Questions (~5/week):** `{ questions:[{ question, options:[≥2], correct:<valid index>, explanation }] }` — a **pool**: each run serves **one** question (currently the first; per-attempt randomization picks one in step 9).
- **Coding:** `{ sequences:[{ title, code (with ____), blanks:[…], options:[…], explanation }] }` — every `____` needs exactly one blank answer, and every answer must be offered among the options.

*(Later: import from Glossary / Question Bank.)*

## 4. Game lineup — a learning ladder (recall → understand → apply)
A linear run on the week's topic; every game's points sum into one course leaderboard.

1. **Crossword — "recall."** Fill the grid from clues (type-to-advance, arrow keys, sticky direction). **No timer.** Teacher-selectable grading: **custom** (default) — full solve takes a finish-rank place (**first 3 = 1.0, 4th–10th = 0.75, later = 0.5**), partial = whole-word fraction × 0.5 **capped at 0.49**; or **regular** — the fraction of fully-correct words (4/10 = 0.40, full solve = 1.0, no rank).
2. **Weekly Question — "understand."** Behind a **Start gate**: press Start → the question reveals and an **option-scaled clock** runs (3 options → 10s … 6 → 25s); the **options are blurred** (tap/hover/click to read one at a time). **Correct = 1.0, wrong = 0**, with instant feedback + explanation.
3. **Coding — "apply."** Behind a **Start gate**; one total clock = Σ per sequence **14s + 6s per extra line**. Sequences are **server-lazy-loaded one at a time** (the finished one unloads to a 🔒 placeholder); within a sequence, lines reveal one at a time and earlier lines **lock**; Submit stays locked until everything is revealed. Sequences share 1.0 evenly, **partial per correct placement**.

Across the attempt: **one hint** (crossword → reveal `ceil(words/2)` letters; question → 50/50; coding → mark the open sequence's correct chips) and **one attempt**. An **Explain** toggle (default on) gates the "why" texts.

## 5. Roles & flow
- **Student** plays the linear run with a bottom-nav stepper: **Start → Crossword → Question → Coding → Today's score + leaderboard preview**.
- **Professor / assistant** **does not play** — they **author** (Question bank tab) and **monitor** (student attempts table + the leaderboard). Branch is on the plugin's own `mod/agon:manage` capability.
- **One course-wide leaderboard**; points **sum cumulatively** across games and weeks.
- **Rewards:** top performers get extra grade points (gradebook wiring is a later phase).

## 6. Anti-cheat / AI-resistance
Goal: **honest play faster than cheating**. **Server-side:** authoritative scoring + the **answer-split** (answers stay on the server, never in `window.AGON`; revealed only *after* a game is submitted); **coding lazy-loaded per sequence** (`mod_agon_get_sequence`) so the full set of code/options is never in the page — the finished sequence unloads to a locked placeholder (nothing to screenshot or inspect); **one attempt + one hint per attempt** enforced (unusable hints refunded). **UI:** Start gates + countdowns (option-scaled question, per-line coding budget; time-up auto-submits); blurred question options (one readable at a time); line-by-line coding reveal with locking and a reveal-gated Submit; crossword is the shareable low-stakes warm-up. **Still to do (step 9):** server-enforced timers and per-attempt randomization — today the countdown is client-side display only.

## 7. Testing & dev environment
- **moodle-docker** runs Moodle 4.5 LTS; the plugin lives at `mod/agon`. See `README.md` for the exact setup, test accounts, run commands and gotchas.
- **Quality (done):** PHPUnit — 103 tests over the engine, web services, lib callbacks, events, privacy and the AI helper (HTTP mocked), ~95% line coverage of `classes/` + `lib.php` (`tests/coverage.php` whitelist); **Behat** — student full-run, mid-run resume, teacher monitor + Question bank flows, guard rails; **Node** — the pure crossword builder. **(planned):** `moodle-plugin-ci` in CI.

## 8. Phased roadmap
- **Phase 0 — Environment. ✅ Done.** moodle-docker + Moodle 4.5; `mod_agon` scaffolded, installed, live.
- **Phase 1 — Playable in Moodle. ✅ Done.** `view.php` renders the student game (Mustache + plain JS) vs a teacher monitor; activity config (game toggles + per-game JSON with guidelines + examples) saved to DB columns; play is content-driven (example fallback); flow respects the enabled games; UI anti-cheat (blur + progressive reveal/lock).
- **Phase 2 — Real backend (mostly done).** Scoring is server-authoritative and the leaderboard is real. Status per step:
  1. ✅ **Data model.** `agon_attempt` (one row per run: `agonid, userid, timestart, timefinish, state, score` + per-game `scorecrossword/scorequestion/scorecoding`, plus `submittedgames` + `hintsused` JSON markers). Via `db/install.xml` + `db/upgrade.php` + `version.php` bumps.
  2. ✅ **Scoring engine (server).** `mod_agon\local\scoring` owns the §4 rules; `mod_agon\local\attempt` runs the lifecycle (start / submit_game / finish). No grading in the browser.
  3. ✅ **Answer split.** `mod_agon\local\content` ships only *renderable* content to `window.AGON` (clues, options, code-with-blanks) — never the `correct` index or coding `blanks`. Reveal-on-submit feedback returns the answers after each game.
  4. ✅ **Comms layer — AMD + web services.** `amd/src/player.js` (module `mod_agon/player`) calls external services in `db/services.php` + `classes/external/`: `start_attempt`, `submit_game`, `finish_attempt`, `get_hint`, `get_leaderboard`. One attempt + one hint per game enforced server-side.
  5. ✅ **Real leaderboard.** `mod_agon\local\leaderboard` sums each student's scores across all agon instances in the course; the student results screen fetches it live, the teacher monitor renders it server-side.
  6. ⏳ **Gradebook.** Add a `grade` column to the `agon` table, re-declare `FEATURE_GRADE_HAS_GRADE`, add `agon_grade_item_update`/`agon_update_grades`, and push the attempt total on `attempt::finish` so the top-performer reward (§4/§5) lands in the gradebook. *(The skeleton's pretend grade hooks — inert `grade_*` functions, scale stubs, `grade.php`, and the do-nothing Grade form section — were deleted in 0.3.1; re-add them properly with this step.)*
  7. ✅ **Capabilities.** `db/access.php` (`mod/agon:addinstance/:play/:manage/:viewleaderboard`); the web services authorize on them; `view.php` and the bank branch on `mod/agon:manage` (0.3.1).
  8. ✅ **Privacy provider.** Real `mod_agon\privacy\provider` (metadata + export + delete + userlist) replacing the `null_provider`.
  9. ⏳ **Enforced timers + randomization.** Server compares its `timestart` against submit time (client countdown becomes display-only); per-attempt question selection from the pool. One known (rare) race remains here: wrap the crossword finish-rank count in a transaction/lock — two simultaneous full solves can currently claim the same rank. *(The other race — `attempt::start` vs the unique key — was fixed in 0.3.1: the insert catches the collision and re-fetches.)*
  10. ✅ **Tests (0.3.1).** PHPUnit: 103 tests / ~95% line coverage over `classes/` + `lib.php` (engine, all 11 web services, lib callbacks, events, generator, privacy, AI with mocked HTTP; `tests/coverage.php` whitelist). Behat: student full run incl. crossword typing + lazy-loaded coding, mid-run resume, teacher monitor + Question bank + bank save, guard rails. Node: the crossword builder engine. **Pending:** `moodle-plugin-ci` in CI.
- **Phase 3 — Authoring + AI + polish (landed 2026-07, releases 0.2.0 → 0.3.1).** ✅ Question bank tab (manual ⇄ JSON, per-game Save + validation); ✅ crossword builder engine + preview; ✅ AI generation (Gemini default / Claude / ChatGPT, copy-prompt fallback, Google Docs/Slides fetch, PDF/PPTX upload); ✅ dual crossword grading; ✅ coding lazy-load; ✅ hint rework (one per attempt, per-game effects, 50/50); ✅ Explain toggle; ✅ site Testing mode (replay); ✅ option-scaled question timer + per-line coding budget.
- **Phase 4 — Full experience (open).** Daily challenge + streaks; glossary/question-bank import; question-pool rotation; screen transitions + micro-animations; dark mode; **backup/restore**; touch drag support. *(Already landed early: Moodle-blue theme, searchable/filterable monitor, branded puzzle-cube icon.)*

## 9. Notes & open questions
- Leaderboard + attempt data are **real** (server-side); the only placeholder left is the gradebook grade (step 6) — today the "extra grade points" reward is awarded by the professor manually, off the leaderboard.
- Content and UI are **English only**. Palette: **Moodle-blue azure** (`--accent #1574cf`) over spray-white/grey cards, with **gold reserved** for the leaderboard medals + hint cues; the old teal/green was retired so the activity blends with Moodle's white page. Correct/wrong stay green/red.
- The activity icon (`pix/monologo.svg`) is a **branded** (own-colours, no purpose tile) black-and-white isometric AGON puzzle cube.
- `prototype/` is a standalone HTML/CSS/JS mock; the real UI lives in `templates/` + `amd/` + `js/` + `styles.css`.
- **Decided (2026-06-09):** Phase 2 started with the data model + server scoring engine; the front end moved to **AMD + external web services** (resolves the earlier "move to AMD?" question).
- Open: gradebook ↔ leaderboard mapping; exact reward mechanism for top performers; touch/drag support (HTML5 DnD + hover don't work on touch); a "not started" filter on the monitor (needs the enrolled-student list); crossword placement validation in the config tool.
