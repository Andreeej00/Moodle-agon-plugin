# Moodle "agon" — Project Plan

*A competitive, gamified learning plugin for Moodle. Living document — last updated 2026-06-04.*

## 1. Vision
A Moodle activity that helps students prep for quizzes by turning course terms into a short, fun, competitive set of minigames. Generic across courses (any subject). Leaderboards are visible to students, teachers, and assistants, and top performers are rewarded with extra grade points. The experience should feel modern, smooth, and a little addictive.

## 2. Plugin shape
- **Type:** activity module `mod_agon` (lives in `/mod/agon/`). Chosen because it's the only plugin type that cleanly gives gradebook integration, a capability system, backup/restore, and per-attempt tracking.
- **Architecture:** one shared **engine** + several pluggable **game renderers**.
  - *Engine* owns: content, attempts, scoring, timing, randomization, leaderboard, gradebook writes. (Like an MVVM ViewModel — shared state.)
  - *Renderers* are the individual games drawing that same state. (Like swappable views/Composables.)
  - A teacher chooses which games are active per activity → some games are universal, some are domain-specific (e.g. the coding game).

## 3. Content model (kept generic)
- **MVP:** teacher types **term + clue/definition + answer** sets directly in the activity. Subject-agnostic.
- **Later:** import from Moodle **Glossary** (concept + definition is already a perfect clue) and/or the **Question Bank**.

## 4. Game lineup — a learning ladder
Each game asks the student to do *more* with the same term: **recall → understand → apply** (Bloom's bottom three rungs). One concept, climbed — not three disconnected games.

1. **Crossword — "recall"** *(professor-required).* Same grid for everyone. **Accepted as shareable** — a shared crossword can't be protected from posting, so it is the **low-stakes warm-up** and carries **light leaderboard weight**.
2. **Reveal & Answer — "understand"** *(timed).* A grid of boxes, each hiding a question/answer. **Only one box is open at a time — opening one closes the last.** Race the clock to open and answer them all. Each answer is **fetched from the server when its box opens**, so the full set never sits on the page at once.
3. **Code Blocks — "apply"** *(timed, language-agnostic).* A snippet split into blocks, each with a **dropdown of choices**; **only one select open at a time**, with options **fetched from the server on open**. Pick the right piece for each slot. Generalizes to non-coding **"fill the blanks in context"** for any subject.

*(Optional future game in the same spirit: a "flashlight" reveal where only a small region of the board is visible at a time.)*

## 5. Competitive layer
- **Leaderboards:** per-activity → per-course (site-wide later). Students see the ranking; teachers/assistants see richer per-student detail (gated by capabilities).
- **Rewards:** top performers get extra points written to the **gradebook**.
- **Scoring weight:** the AI-resistant games (2 & 3) count for more than the shareable crossword.
- **Engagement (later):** a **Daily Run** + streaks — one term a day, climb the ladder, streak bonus.
- **Privacy:** the leaderboard shows names → expose via a setting and the Moodle Privacy API.

## 6. Anti-cheat / AI-resistance
The goal is **not** "AI-proof" (multimodal AI can read a screenshot) but **making honest play faster than cheating**. All levers are **server-enforced**:
1. **Tight timers** — no time to round-trip an AI mid-round.
2. **Per-attempt randomization / shuffling** — a shared or screenshotted answer goes stale. *(Not applicable to the daily shared puzzle — there, rely on timer + first-attempt-counts.)*
3. **Progressive disclosure** — never show the whole puzzle at once; reveal one piece at a time, **fetched from the server on open** so the full answer set never exists in the page DOM. This defeats both screenshot *and* HTML-inspection cheating. (Note: native `<select>` options live in the DOM even when closed — so options/answers must be **lazy-loaded on open**, not pre-rendered.) Implemented as an engine-level mode any game can enable.
4. **Interaction that doesn't copy-paste** — drag / select / spatial.

## 7. Testing & dev environment
- **moodle-docker** to run Moodle + a database in containers; mount `mod_agon` into the Moodle tree.
- **Mock data:** `admin/tool/generator` for test courses, plus a few dummy student accounts to exercise the leaderboard.
- **Quality:** Moodle coding style + `moodle-plugin-ci`; PHPUnit/Behat tests (the skeleton generator scaffolds these).

## 8. Phased roadmap
- **Phase 0 — Environment.** moodle-docker up; scaffold `mod_agon` with `tool_pluginskel`; it installs and appears when adding an activity to a test course.
- **Phase 1 — Engine + first game (MVP).** Teacher content authoring → one game end-to-end → server-side scoring → gradebook write → basic leaderboard. Prove the whole pipeline (start with the simplest game to de-risk).
- **Phase 2 — Competitive layer.** Per-course leaderboard, capabilities, reward rules, the AI-resistance levers.
- **Phase 3 — Remaining games + polish.** Games 2 & 3, Daily Run + streaks, glossary/question-bank import, animations, responsive + dark mode, accessibility.

## 9. Notes & open questions
- The downloaded folder is `tool_pluginskel` — the plugin skeleton **generator**, not the crossword plugin. We'll use it to scaffold `mod_agon`.
- Open: target Moodle version (default: latest LTS); whether the Daily Run lands in Phase 1 or later; exact leaderboard scoring weights; team size/roles; front-end build tooling (Grunt/AMD modules + Mustache).
