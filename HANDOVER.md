# Agon — Handover

Practical notes for anyone picking up or running this project. For the *what/why* see [plan.md](plan.md); for the *how it's built* see [docs/architecture.md](docs/architecture.md).

## 1. What it is

`mod_agon` is a Moodle activity module: a weekly, competitive run of three mini-games (crossword → weekly question → coding) that helps students revise, with one course leaderboard and extra grade points for top performers. University project.

## 2. Current status (2026-06-08)

**Works now (Phase 1):**
- Installs and runs in Moodle 4.5 LTS.
- Teacher **settings form**: pick which games the activity includes + paste each game's **JSON** content (with guideline text + editable examples). Saved to the `agon` table.
- **Student play** (inside Moodle), content-driven from the saved JSON, flow respects which games are enabled:
  - crossword grid + clues;
  - weekly question — **Start gate → 10s countdown**, **blurred options** (tap/hover), instant correct/incorrect feedback;
  - coding — **Start gate → 45s countdown**, **line-by-line reveal + previous-line locking**, drag/tap placement, per-blank feedback;
  - per-game **hints** (letter / explanation / blank cue), per-screen **feedback**, screen **transitions**, animated **score count-up**, tap-friendly UI.
- **Teacher monitor** view (attempts + leaderboard) instead of playing.

**Real now (Phase 2 progress, 2026-06-10):**
- **Scoring, attempts, hints and the course leaderboard are server-side** — `agon_attempt` table, scoring engine, web services, AMD player (`amd/src/player.js`). Answers never reach the browser; one attempt + one hint per game enforced; partial crossword = fraction × 0.5 capped at 0.49.
- `db/access.php` capabilities and a real privacy provider (export/delete) are in.

**Still pending (Phase 2 tail):**
- Gradebook export (plan §8 step 6 — also adds the missing `grade` column).
- Server-enforced timers + per-attempt question randomization (step 9, incl. two noted rare races); backup/restore (Phase 3).
- Accessibility is **in place** (keyboard-operable controls, ARIA labels + live announcements, focus management, focus rings, WCAG-AA contrast). Native drag is desktop-only — the keyboard/tap path covers it. Still worth a real VoiceOver pass to confirm.

## 3. Run it locally (moodle-docker)

```sh
# one-time: clone Moodle core + the docker tooling (see the moodle-docker README)
#   ~/documents/moodle-dev/moodle           (Moodle 4.5, branch MOODLE_405_STABLE)
#   ~/documents/moodle-dev/moodle-docker     (github.com/moodlehq/moodle-docker)
# this plugin lives at ~/documents/moodle-dev/moodle/mod/agon  (it IS this git repo)

cd ~/documents/moodle-dev/moodle-docker
export MOODLE_DOCKER_WWWROOT=~/documents/moodle-dev/moodle
export MOODLE_DOCKER_DB=pgsql
export MOODLE_DOCKER_WEB_PORT=8000
bin/moodle-docker-compose up -d          # start (stop/start to pause/resume)
```

Moodle runs at **http://localhost:8000**.

- **Admin:** `admin` / `Admin123!`
- **Test student** (enrolled in the test course): `agonstu` / `Agonstu123!`

To see the **student** game, log in as `agonstu` (an incognito window is cleanest). As admin you get the **teacher monitor**. Avoid "Switch role to Student" — it leaves edit mode on / a hybrid state.

## 4. Dev gotchas (these cost real time)

- **Bind-mount sync lag.** After adding a **new** file/folder, PHP can't see it until `bin/moodle-docker-compose restart webserver`. Editing existing files is *usually* instant, but the mount occasionally lags on content too — **if an edit doesn't show up, restart the webserver** and re-check (`exec -T webserver grep -c <marker> <file>`). New classes also want `find /var/www/moodledata -name 'core_component*.php' -delete` then `php admin/cli/purge_caches.php`.
- After editing **templates or CSS**, run `... exec -T webserver php admin/cli/purge_caches.php`.
- **Student JS is now an AMD module** (`amd/src/player.js`). The container has no grunt, so after editing the source, mirror it to the build (`cp amd/src/player.js amd/build/player.min.js`) and run `php admin/cli/purge_caches.php`. (The teacher monitor still uses plain `js/professor.js`, cache-busted by file mtime in `view.php`.)
- **One attempt per student is enforced.** To replay as a test student, clear the row(s): `exec -T webserver php -r 'define("CLI_SCRIPT",1);require("/var/www/html/config.php");$DB->delete_records("agon_attempt");'` (or scope by `agonid`/`userid`).
- `$PAGE->requires->js($url, $inhead)` — second arg **must be `false`** (footer), so the script runs after the inline `window.AGON` data. `true` = `<head>` = script runs before the data and silently bails.
- The `agon` table only stores fields whose **column name matches** the form field (Moodle drops the rest), so new settings need a new column (`db/install.xml` + `db/upgrade.php` + a version bump in `version.php`, then `php admin/cli/upgrade.php`).

## 5. Key files

| Path | What |
| --- | --- |
| `version.php` | plugin version (bump when adding DB columns / shipping) |
| `db/install.xml`, `db/upgrade.php` | schema: `agon` table incl. `game*` toggles + `content*` JSON |
| `mod_form.php` | settings form: game checkboxes + per-game JSON boxes |
| `view.php` | renders student game / teacher monitor; builds `window.AGON` from saved JSON |
| `templates/student.mustache`, `professor.mustache` | the UI markup |
| `js/student.js`, `js/professor.js` | the play logic / monitor tables (plain JS) |
| `styles.css` | scoped under `.agon` |
| `lib.php` | Moodle callbacks (`agon_add_instance`, gradebook hooks, …) |
| `classes/event/` | course-module-viewed events |
| `prototype/` | standalone HTML/CSS/JS design mock (not shipped) |

## 6. Next steps (Phase 2 — in progress)

Decided 2026-06-09: build the **data model + server scoring engine** first; move the front end to **AMD + external web services** for start/submit. Order (full list in [plan.md](plan.md) §8):

1. `agon_attempt` table + version bump (per-run row: times, state, total + per-game scores).
2. Server-side scoring engine (the §4 rules) → real per-activity result; no grading in the browser.
3. Answer-split: `view.php` stops shipping `correct`/`blanks` in `window.AGON`.
4. AMD + web services (`start_attempt` / `submit` / `finish`) + one-attempt enforcement.
5. Real cumulative course leaderboard + gradebook grade.
6. `db/access.php` capabilities + real privacy provider.
7. Server-enforced timers + per-attempt randomization.
8. Real PHPUnit/Behat tests.

## 7. Deploying to a real (e.g. university) Moodle

Zip the plugin with a single top folder named **`agon`** (exclude `prototype/`, `.git/`, docs if you like), then *Site administration → Plugins → Install plugins → upload*. Must match the target Moodle version (built on **4.5 LTS**).
