# Architecture

Technical companion to [plan.md](../plan.md). This describes *how* `mod_agon` is built; `plan.md` covers *what* and *why*. Sections marked **(planned)** are not built yet; everything else reflects the current code (release **0.3.1**).

## Plugin type

`mod_agon` is a Moodle **activity module** (`/mod/agon/`) — giving a per-instance DB table + settings form, a capability system, per-user attempt tracking, events and a privacy provider. (Gradebook export and backup/restore are **(planned)** — see the honest-gaps list at the end.)

## The engine + pluggable games

**One shared server-side engine with swappable game "renderers"** — like an MVVM ViewModel (shared state) with interchangeable views.

- **Engine (`classes/local/`):** the server owns the truth.
  - `scoring` — pure scoring functions (no DB, no request): question right/wrong, per-blank coding credit, crossword solution/word-fraction helpers and both crossword grading rules.
  - `attempt` — the attempt lifecycle (`start` / `submit_game` / `finish` / `use_hint` / `reset`); one row per student per instance (unique key), **one attempt** and **one hint per attempt** enforced server-side; `start` absorbs races via the unique key.
  - `content` — loads the saved per-game JSON and shapes it per audience: `raw()` (answers included, server only), `public_for_render()` (answers stripped; coding reduced to *metadata only*), `coding_sequence_public()` (one lazy-loaded sequence, no answers), `feedback()`/`feedback_from()` (reveal-on-submit), `hint()` (the one hint), plus `validate_game()`/`save_game()` for authoring.
  - `leaderboard` — cumulative course-wide totals + the per-instance attempts report.
  - `ai` — Question-bank generation: game prompts, Google Docs/Slides fetch, PDF/PPTX text extraction, and the three provider calls (Gemini default, Claude/ChatGPT with a teacher key).
- **Renderers (per game):** each game reads the same shaped content and draws its own UI in `amd/src/player.js`. Adding a game = a renderer + a scoring rule + a content shape.

## How it renders in Moodle

- **`view.php`** loads the instance, branches on **`mod/agon:manage`** (monitor for teachers, play for students), and builds `window.AGON` from `content::public_for_render()` — clues, options and *coding metadata* only; never a `correct` index, crossword letters or coding `blanks`/code. If nothing is playable (toggle off, or empty/invalid JSON) the student gets a "not configured" notice instead of banking a zero-score run.
- **Student play** is the AMD module `mod_agon/player` (`amd/src/player.js`), booted with `js_call_amd(..., [cmid])`. It drives everything through the web services and renders verdicts only from the server's reveal-on-submit feedback. No grunt in the dev container, so `amd/build/*.min.js` are hand-mirrored copies.
- **Teacher monitor** is plain `js/professor.js` over the real attempt data in `window.AGON` (name search + state filter + course leaderboard).
- **Question bank** (`bank.php` + `templates/bank.mustache` + `amd/src/bank.js`) is a secondary-navigation tab added in `agon_extend_settings_navigation` for `mod/agon:manage` holders. Manual ⇄ JSON editors per game, the crossword **builder** (`amd/src/crossword.js`, a pure deterministic layout engine shared with the preview), per-game Save through `mod_agon_save_content`, and the shared **Generate with AI** panel.
- **Templates:** `student.mustache`, `professor.mustache`, `bank.mustache`. **CSS:** `styles.css`, scoped under `.agon`.

## Content model — JSON per game (stored on the instance)

The activity settings form has only the three game toggles (`game*` columns); content lives in the `content*` text columns and is authored in the Question bank (manual, JSON, or AI). `content::validate_game()` guards every save:

- **Crossword:** `{ grading: "custom"|"regular", words:[{ number, word, clue, direction, row≥0, col≥0 }] }` — layout comes from the builder.
- **Questions:** `{ questions:[{ question, options:[≥2], correct:<valid index>, explanation }] }` — a pool; the run serves the first.
- **Coding:** `{ sequences:[{ title, code (with ____), blanks:[…], options:[…], explanation }] }` — every `____` must have exactly one `blanks` entry and every blank must be offered in `options`.

## Anti-cheat & play mechanics

**Server-side (authoritative):**
- **The browser never decides a grade.** It sends only student input; `attempt::submit_game` grades via `scoring` and persists. Answers arrive only as reveal-on-submit feedback.
- **Coding is lazy-loaded per sequence** (`mod_agon_get_sequence`): only the sequence in view ever exists in the page; advancing snapshots the answers and unloads the finished card to a 🔒 placeholder. Nothing to screenshot, nothing in the DOM to inspect.
- **One attempt** (unique key) and **one hint per attempt** (`hintsused`), both enforced in `attempt`; an unusable hint (nothing to reveal) is refunded.
- Hints are shaped server-side: crossword reveals `ceil(words/2)` unfilled letters, question is a 50/50 (`floor(options/2)` wrong options removed), coding marks the open sequence's correct chips.

**Client-side (UI, supporting):**
- **Start gates + countdowns** on question/coding; time-up auto-submits. Question budget scales with options (3→10s … 6→25s); coding budget = Σ per sequence `14s + 6s × (lines − 1)` from the server-counted line metadata.
- Question options are blurred until hovered/tapped; picking another option re-blurs the last. Coding reveals line-by-line with earlier lines locking, and **Submit stays locked until every sequence/line is revealed**.
- The **Explain** toggle (default on) gates the "why" texts (question explanation, coding review, revealed clue answers); the review screen is skipped entirely when off.

**(planned):** server-enforced timers (compare `timestart` to submit time) and per-attempt randomization — the countdown is client-side display only.

## Scoring

| Game | Rule |
| --- | --- |
| Crossword (custom, default) | full solve joins the finish-rank ladder: 1st–3rd **1.0**, 4th–10th **0.75**, later **0.5**; partial = whole-word fraction × 0.5, capped **0.49** |
| Crossword (regular) | fraction of fully-correct words (4/10 = **0.40**; full solve **1.0**, no rank) |
| Question | correct **1.0**, wrong/none **0** |
| Coding | sequences share 1.0 evenly; partial credit per correctly placed blank |

The teacher picks the crossword rule in the bank (stored as `grading` inside the crossword JSON). The leaderboard sums attempt totals across every agon instance in the course.

## Data model

- `agon` — instance: name/intro, `gamecrossword|question|coding` toggles, `contentcrossword|question|coding` JSON.
- `agon_attempt` — one run: `agonid, userid, state(inprogress|finished), timestart, timefinish, score, scorecrossword, scorequestion, scorecoding, submittedgames(JSON), hintsused(JSON)`; unique `(agonid, userid)`, FKs to `agon` and `user`.

## Web services (`db/services.php` + `classes/external/`)

AJAX external functions (shared traits: `uses_agon_context` for cm/capability resolution, `returns_attempt_summary` for the common return shape):

| Function | Cap | Purpose |
| --- | --- | --- |
| `mod_agon_start_attempt` | play | start or resume the attempt |
| `mod_agon_submit_game` | play | grade one game, return summary + feedback |
| `mod_agon_finish_attempt` | play | finish (requires every playable game submitted) |
| `mod_agon_get_hint` | play | spend the one hint |
| `mod_agon_get_sequence` | play | lazy-load one coding sequence (no answers) |
| `mod_agon_get_leaderboard` | viewleaderboard | live course leaderboard |
| `mod_agon_save_content` | manage | validate + store one game's JSON |
| `mod_agon_ai_prompt` | manage | build the copy-into-any-AI prompt |
| `mod_agon_ai_generate` | manage | call the AI provider, return content JSON |
| `mod_agon_fetch_source` | manage | read a shared Google Docs/Slides link |
| `mod_agon_extract_file` | manage | extract text from an uploaded PDF/PPTX |

## Admin settings (`settings.php`)

- **AI:** `aienable` (off by default), `aiprovider` (google/anthropic/openai), `aiapikey` (or `$CFG->forced_plugin_settings['mod_agon']['aiapikey']` in config.php), `aimodel` (default `gemini-2.5-flash`).

## Moodle APIs

- **Capabilities** (`db/access.php`): `mod/agon:addinstance`, `:play` (students), `:manage` (teachers — monitor view, bank tab, authoring services), `:viewleaderboard` (everyone).
- **Events**: `course_module_viewed` (view.php), `course_module_instance_list_viewed` (index.php).
- **Privacy**: full provider — metadata + contexts/userlist + export + all three delete paths over `agon_attempt`.
- **Icon**: `agon_is_branded()` so `pix/monologo.svg` renders in its own colours.

## Testing

- **PHPUnit** (`tests/`): `scoring`, `content`, `attempt`, `leaderboard`, `lib`, `generator`, `local/ai` (provider HTTP mocked via `curl::mock_response`), `event/events`, `external/services` (play loop), `external/authoring_services` (bank + AI), `privacy/provider`. Coverage whitelist in `tests/coverage.php` (classes/ + lib.php). Run: `vendor/bin/phpunit --testsuite mod_agon_testsuite`.
- **Behat** (`tests/behat/`): the full student play-through (crossword typing → question → lazy-loaded coding → review → results + leaderboard), mid-run resume, the teacher monitor + Question bank tab + bank save, and the guard rails (not-configured notice, disabled games out of the stepper). Run: `vendor/bin/behat --tags=@mod_agon` from the behat setup.
- **Node** (`tests/js/crossword_test.js`): the pure crossword builder engine — validation, placement legality, determinism, numbering. Run: `node mod/agon/tests/js/crossword_test.js`.

## Honest gaps (planned, not pretended)

- **Gradebook export** — attempt scores feed the leaderboard only; nothing writes grade items (and `FEATURE_GRADE_HAS_GRADE` is deliberately not declared, so the settings form shows no dead Grade section). Wiring: a `grade` column + `agon_grade_item_update`/`agon_update_grades` + a push on `attempt::finish`.
- **Backup/restore** (`backup/moodle2/`) — an agon activity does not survive course backup yet.
- **Server-enforced timers / per-attempt randomization** — countdowns are client display; the question pool always serves the first entry.
- **Question pool rotation** — `questions[]` accepts many, the run uses `[0]`.
