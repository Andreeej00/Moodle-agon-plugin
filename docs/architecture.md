# Architecture

Technical companion to [plan.md](../plan.md). This describes *how* `mod_agon` is built; `plan.md` covers *what* and *why*. Sections marked **(planned)** are not built yet; everything else reflects the current code.

## Plugin type

`mod_agon` is a Moodle **activity module** (`/mod/agon/`) — giving a per-instance DB table + settings form, gradebook hooks, a capability system, backup/restore, and per-user attempt tracking.

## The engine + pluggable games

**One shared server-side engine with swappable game "renderers"** — like an MVVM ViewModel (shared state) with interchangeable views.

- **Engine (`classes/local/`):** the server owns the truth.
  - `scoring` — pure functions for the §4 rules (no DB, no request).
  - `attempt` — the attempt lifecycle (`start` / `submit_game` / `finish` / `use_hint`); one row per student per instance, one attempt + one hint per game enforced.
  - `content` — loads the saved per-game JSON and shapes it three ways: `raw()` (answers, for scoring), `public_for_render()` (answers stripped, for the browser), and `feedback()` / `hint()` (answers revealed deliberately).
  - `leaderboard` — cumulative course-wide totals + the per-instance attempts report.
- **Renderers (per game):** each game reads the same content and draws its own UI in the front end. Adding a game = adding a renderer + its scoring rule.

## How it renders in Moodle (current)

- **`view.php`** loads the instance, branches by capability (`moodle/course:manageactivities` → teacher **monitor** view; else **student** play), and builds `window.AGON` from `content::public_for_render()` (**answer-free** — clues, options, code-with-blanks; never the `correct` index or coding `blanks`). If no game is playable (toggle off or empty/invalid JSON) the student sees a themed "not configured" notice instead of an empty run.
- **Student play** is an **AMD module** (`amd/src/player.js` → `mod_agon/player`), loaded via `$PAGE->requires->js_call_amd(...)`. It drives the run through the web services (below) and renders each game's verdict from the server's reveal-on-submit feedback. (No grunt in the container, so `amd/build/player.min.js` is a hand-mirrored copy of the source.)
- **Teacher monitor** is plain `js/professor.js` (the attempts table with name search + a state filter, plus the leaderboard), loaded with a `filemtime` cache-buster.
- **Templates:** `templates/student.mustache`, `templates/professor.mustache`.
- **CSS:** `styles.css`, every rule scoped under `.agon`; a Moodle-blue theme (azure `#1574cf` + spray-white/grey cards) that sits on Moodle's white page, with gold reserved for leaderboard medals and hint cues.

## Content model — JSON per game (stored on the instance)

The professor enables games (`gamecrossword`/`gamequestion`/`gamecoding` columns) and pastes each game's JSON (`contentcrossword`/`contentquestion`/`contentcoding` text columns). `mod_form.php` validates on save (each enabled game must decode to a non-empty `words`/`questions`/`sequences` list). Per week/topic:

- **Crossword:** `{ subject, subject_code, week, topic, language, difficulty, author, words:[{ number, word, clue, direction, row, col }] }`
- **Questions:** `{ subject_code, week, topic, questions:[{ question, options:[…], correct:index, explanation }] }` — a pool; one question is served per run.
- **Coding:** `{ subject_code, week, sequences:[{ title, code (with ____), blanks:[…], options:[…] }] }`

Saving works because the column names match the form field names (Moodle `insert_record`/`update_record` map them).

## Anti-cheat & play mechanics

**Client-side (UI):**
- **Start gate + countdown:** the question/coding stages hide their content behind a "Start" button; on Start the content renders and a `setInterval` countdown runs (10s / 45s); time-up auto-submits to the server.
- Question options blurred, cleared on hover, tap or focus (`.opt.peek` / `.opt.is-revealed`).
- Coding split into per-line `.codeline` divs; only the first shows; "reveal next line" un-hides the next and `.is-locked`s the blanks above.

**Server-side (authoritative):**
- **The browser never decides a grade.** It sends only the student's *input*; `attempt::submit_game` runs it through `scoring` and writes the score. Answers live server-side and are returned only as **reveal-on-submit** feedback once a game is over.
- **One attempt + one hint per game**, tracked by the `submittedgames` / `hintsused` columns; resubmits and second hints are refused. Hints are issued by the `get_hint` service so they can't become an answer oracle.

**(planned):** server-enforced timers (compare the server `timestart` to submit time) and per-attempt randomization — today the countdown is client-side display only.

## The three games

| Game | Rung | Mechanic | Timed |
| --- | --- | --- | --- |
| Crossword | Recall | Fill grid from clues (shared grid) | no |
| Weekly Question | Understand | Blurred options (hover to read); pick one | yes |
| Coding | Apply | Two sequences revealed line-by-line; drag/click options into blanks; earlier lines lock | yes |

## Scoring & integrity

Scoring is **server-authoritative**: the client posts the student's input, the server grades it via `scoring`, recomputes the attempt total, and (planned) will write the gradebook grade. The crossword rule is split — a full solve takes a finish-rank place (1.0 / 0.75 / 0.5, counted live from prior full solvers), while a partial scores `fraction × 0.5` capped at **0.49** so it can never reach a full solve. The leaderboard sums each student's attempt scores across every agon instance in the course.

## Data model

- `agon` — the activity instance (game toggles + per-game JSON content).
- `agon_attempt` — one student's run: `agonid, userid, state, timestart, timefinish, score` + per-game `scorecrossword/scorequestion/scorecoding`, plus `submittedgames` and `hintsused` (JSON markers). Unique key on `(agonid, userid)` enforces one attempt; FKs to `agon` and `user`.

## Web services (`db/services.php` + `classes/external/`)

AJAX-only external functions the AMD player calls (shared context/return traits in `uses_agon_context` + `returns_attempt_summary`):

- `mod_agon_start_attempt` — start or resume the current user's attempt.
- `mod_agon_submit_game` — grade one game and return the updated attempt + reveal-on-submit feedback.
- `mod_agon_finish_attempt` — finish the run (requires every playable game submitted).
- `mod_agon_get_hint` — spend the one hint for a game.
- `mod_agon_get_leaderboard` — the live cumulative course leaderboard.

## Moodle APIs

- **Capabilities** — `db/access.php`: `mod/agon:addinstance/:play/:manage/:viewleaderboard`; the web services authorize on `:play` / `:viewleaderboard`. **(planned)** `view.php`'s teacher branch still uses `moodle/course:manageactivities`; switch it to `mod/agon:manage`.
- **Privacy** — real provider (`classes/privacy/provider.php`): metadata + export + delete + userlist for `agon_attempt`.
- **Icon** — `agon_is_branded()` marks `pix/monologo.svg` as branded (rendered in its own colours, no purpose container).
- **Gradebook (planned)** — grade computation in `lib.php` (`agon_update_grades`), once the `agon.grade` column exists.
- **Backup/restore (planned)** — under `backup/moodle2/`.

## Testing

- **moodle-docker** for a local Moodle + DB (see `README.md`).
- **PHPUnit** across `tests/`: `scoring`, `attempt`, `content`, `leaderboard`, `external/services`, `privacy/provider`. **(planned)** Behat for the play flow + `moodle-plugin-ci` in CI.
