# Architecture

Technical companion to [plan.md](../plan.md). This describes *how* `mod_agon` is built; `plan.md` covers *what* and *why*. Sections marked **(planned)** are not built yet.

## Plugin type

`mod_agon` is a Moodle **activity module** (`/mod/agon/`) — giving a per-instance DB table + settings form, gradebook hooks, a capability system, backup/restore, and per-user attempt tracking.

## The engine + pluggable games

**One shared engine with swappable game "renderers"** — like an MVVM ViewModel (shared state) with interchangeable views.

- **Engine (shared):** content, attempts, scoring, timing, randomization, leaderboard, gradebook. *(Today this is partly the front-end + config storage; the server-side engine is Phase 2.)*
- **Renderers (per game):** each game reads the same data and draws its own UI. Adding a game = adding a renderer + its scoring rule.

## How it renders in Moodle (current)

- **`view.php`** loads the instance, branches by capability (`moodle/course:manageactivities` → teacher **monitor** view; else **student** game), builds a `$agondata` array from the saved content (with an example fallback), renders a Mustache template, and prints the data as `window.AGON` via `html_writer::script`.
- **Templates:** `templates/student.mustache`, `templates/professor.mustache`.
- **JS:** plain `js/student.js`, `js/professor.js`, loaded with `$PAGE->requires->js($url, false)` — **footer** (false) so the script runs *after* the `window.AGON` block. URL has a `filemtime` cache-buster. (Not AMD yet.)
- **CSS:** `styles.css`, every rule scoped under `.agon` to avoid clashing with Moodle/Bootstrap.

## Content model — JSON per game (stored on the instance)

The professor enables games (`gamecrossword`/`gamequestion`/`gamecoding` columns) and pastes each game's JSON (`contentcrossword`/`contentquestion`/`contentcoding` text columns). Per week/topic:

- **Crossword:** `{ subject, subject_code, week, topic, language, difficulty, author, words:[{ number, word, clue, direction, row, col }] }`
- **Questions:** `{ subject_code, week, topic, questions:[{ question, options:[…], correct:index, explanation }] }`
- **Coding:** `{ subject_code, week, sequences:[{ title, code (with ____), blanks:[…], options:[…] }] }`

`mod_form.php` provides the toggles + per-game textareas (with guideline text + editable example defaults, shown/hidden via `hideIf`). Saving works because the column names match the form field names (Moodle `insert_record`/`update_record` map them).

## Anti-cheat & play mechanics

**Implemented (client-side UI):**
- **Start gate + countdown:** the question/coding stages hide their content behind a "Start" button; on Start the content renders and a `setInterval` countdown runs (10s / 45s); time-up auto-submits.
- Question options blurred, cleared on hover or tap (`.opt.peek` / `.opt.is-revealed`).
- Coding split into per-line `.codeline` divs; only the first shows; "reveal next line" un-hides the next and `.is-locked`s the blanks above.
- **Per-game hints** (reveal a crossword letter / show the question explanation / cue the next code blank) and **per-screen feedback** (grade client-side, colour cells/options/blanks, show a banner).

**(planned, server-side):** server-authoritative scoring, server-enforced timers, per-attempt randomization. The browser must never decide the grade — the client-side grading here is skeleton-only and exposes answers in `window.AGON`.

## The three games

| Game | Rung | Mechanic | Timed |
| --- | --- | --- | --- |
| Crossword | Recall | Fill grid from clues (shared grid) | no |
| Weekly Question | Understand | Blurred options (hover to read); pick one | yes |
| Coding | Apply | Two sequences revealed line-by-line; drag/click options into blanks; earlier lines lock | yes |

## Scoring & integrity (planned)

Scoring will be **server-authoritative** — the server issues the puzzle, starts the timer, validates the solution, and writes the grade. Today the leaderboard/score values are placeholders rendered in the UI.

## Data model (Phase 2, in progress)

- `agon` — the activity instance (exists; also holds the game toggles + JSON content).
- `agon_attempt` — a student's run: `agonid, userid, timestart, timefinish, state, score` + per-game `scorecrossword/scorequestion/scorecoding`.
- *(optional)* `agon_response` — per-item answers for detailed scoring/analytics.

## Build order (Phase 2)

Decided 2026-06-09: start with the **data model + server scoring engine**, and move the front end to **AMD + external web services** for start/submit. Sequence: (1) `agon_attempt` schema, (2) PHP scoring engine, (3) answer-split so `view.php` stops shipping answers in `window.AGON`, (4) AMD + web services (`start_attempt`/`submit`), (5) real leaderboard, (6) gradebook, (7) capabilities, (8) privacy, (9) enforced timers + randomization, (10) tests. Full list in [plan.md](../plan.md) §8.

## Moodle APIs still to wire (planned)

- **External / web services** (`db/services.php` + `classes/external/`) for `start_attempt`, `submit`, lazy fetches — AMD front end calls these. *(Phase 2 comms layer.)*
- **Gradebook** grade computation in `lib.php` (`agon_update_grades`).
- **Capabilities** in `db/access.php` (`:play`, `:manage`, `:viewleaderboard`, …); `view.php` branch moves from `moodle/course:manageactivities` to `mod/agon:manage`.
- **Privacy** provider update (currently declares no personal data; will change once attempts are stored).
- **Backup/restore** under `backup/moodle2/`.

## Testing

- **moodle-docker** for a local Moodle + DB (see `HANDOVER.md`). **PHPUnit** on the engine and **Behat** for flows are planned; **moodle-plugin-ci** in GitHub Actions once there's logic to test.
