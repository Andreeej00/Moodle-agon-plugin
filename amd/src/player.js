// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Student play flow for mod_agon.
 *
 * Reads the answer-free content from window.AGON and drives every grade, hint
 * and score through the server web services — the browser never decides a
 * grade, and answers only arrive in the reveal-on-submit feedback.
 *
 * @module     mod_agon/player
 * @copyright  2026 Andrej Micic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    /**
     * Call one external function and return its promise.
     *
     * @param {String} methodname
     * @param {Object} args
     * @return {Promise}
     */
    function call(methodname, args) {
        return Ajax.call([{methodname: methodname, args: args}])[0];
    }

    return {
        /**
         * Initialise the play flow.
         *
         * @param {Number} cmid The course module id, for the web services.
         */
        init: function(cmid) {
            var CMID = cmid;
            var D = window.AGON;
            if (!D) {
                window.console && window.console.error('Agon: no data (window.AGON missing)');
                return;
            }

            var $ = function(id) { return document.getElementById(id); };
            var root = $('agon-app') || document;
            var games = D.enabledGames || {crossword: true, question: true, coding: true};
            var esc = function(s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            };
            var announce = function(msg) {
                var el = $('agon-live');
                if (el) { el.textContent = ''; setTimeout(function() { el.textContent = msg; }, 30); }
            };

            // Stepper icons (Lucide/Feather-style strokes; the grid echoes the plugin logo).
            var svg = function(inner) {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
                    ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + inner + '</svg>';
            };
            var icons = {
                crossword: svg('<rect x="3" y="3" width="18" height="18" rx="1.5"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/>' +
                    '<path d="M3 3h6v6H3z" fill="currentColor" stroke="none"/><path d="M15 9h6v6h-6z" fill="currentColor" stroke="none"/>'),
                question: svg('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'),
                coding: svg('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'),
                result: svg('<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/>' +
                    '<path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/>' +
                    '<path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>')
            };

            // Build the flow + stepper from the enabled games.
            var steps = [], flow = ['start'];
            if (games.crossword) { steps.push({label: 'Crossword', icon: icons.crossword, stages: ['crossword']}); flow.push('crossword'); }
            if (games.question) { steps.push({label: 'Question', icon: icons.question, stages: ['question']}); flow.push('question'); }
            if (games.coding) { steps.push({label: 'Code', icon: icons.coding, stages: ['coding', 'review']}); flow.push('coding', 'review'); }
            steps.push({label: 'Result', icon: icons.result, stages: ['results']}); flow.push('results');
            var stepOf = {start: 0};
            steps.forEach(function(st, i) { st.stages.forEach(function(sg) { stepOf[sg] = i; }); });

            var idx = 0, userNavigated = false, finished = false;
            var submitted = {}, submitting = {}, hintUsed = false, scores = {}, cwMap = {}, feedback = {};
            // Which coding sequence is currently open (the rest are hidden ahead / blurred behind).
            var codeSeq = 0;
            // Crossword cursor direction: 'across' or 'down'. Sticky — only arrow keys (and
            // focusing a single-word cell) change it, so a word being typed never bumps onto
            // a small crossing word. Drives type-to-advance and backspace.
            var cwActiveDir = 'across';
            var isGame = function(n) { return n === 'crossword' || n === 'question' || n === 'coding'; };

            // ---------- timed games: Start gate + countdown ----------
            var started = {}, timerId = null;
            var fmt = function(s) { var m = Math.floor(s / 60), x = s % 60; return '⏱ ' + m + ':' + (x < 10 ? '0' : '') + x; };
            var stopTimer = function() { if (timerId) { clearInterval(timerId); timerId = null; } };
            // Question budget scales with the number of answer options: 3→10, 4→15, 5→20, 6→25s.
            var questionSeconds = function() {
                var q = (D.questions && D.questions[0]) || null;
                var n = (q && q.options) ? q.options.length : 4;
                n = Math.max(3, Math.min(6, n));
                return 5 * (n - 1);
            };
            // Coding budget: 14s per sequence, +6s for each extra line (sub-sequence) in it.
            var codingSeconds = function() {
                var seqs = (D.coding && D.coding.sequences) || [];
                var total = 0;
                seqs.forEach(function(s) {
                    var lines = (s.code || '').split('\n').filter(function(l) { return l.trim() !== ''; }).length || 1;
                    total += 14 + 6 * (lines - 1);
                });
                return total || 14;
            };
            var startTimer = function(seconds, el, onEnd) {
                stopTimer();
                var rem = seconds;
                if (el) { el.textContent = fmt(rem); el.classList.remove('is-low'); }
                timerId = setInterval(function() {
                    rem--;
                    if (el) { el.textContent = fmt(rem); if (rem <= 5) { el.classList.add('is-low'); } }
                    if (rem <= 0) { stopTimer(); announce("Time's up."); if (onEnd) { onEnd(); } }
                }, 1000);
            };
            var gate = function(key) {
                if ($('ready-' + key)) { $('ready-' + key).hidden = false; }
                if ($('timer-' + key)) { $('timer-' + key).hidden = true; }
                if (key === 'q' && $('q-host')) { $('q-host').hidden = true; }
                if (key === 'code' && $('coding')) { $('coding').hidden = true; }
            };
            var focusFirst = function(sel) {
                var el = root.querySelector('.stage.is-active ' + sel);
                if (el && el.focus) { el.focus(); }
            };
            var startGame = function(cur) {
                started[cur] = true;
                if (cur === 'question') {
                    if ($('ready-q')) { $('ready-q').hidden = true; }
                    if ($('q-host')) { $('q-host').hidden = false; }
                    if ($('timer-q')) { $('timer-q').hidden = false; }
                    renderQuestion();
                    var qsecs = questionSeconds();
                    startTimer(qsecs, $('timer-q'), function() { doSubmit('question'); });
                    announce('Question revealed. ' + qsecs + ' seconds on the clock.');
                    // Don't auto-focus an option — focusing must not reveal one (see renderQuestion).
                } else if (cur === 'coding') {
                    if ($('ready-code')) { $('ready-code').hidden = true; }
                    if ($('coding')) { $('coding').hidden = false; }
                    if ($('timer-code')) { $('timer-code').hidden = false; }
                    renderCoding();
                    var secs = codingSeconds();
                    startTimer(secs, $('timer-code'), function() { doSubmit('coding'); });
                    announce('Code revealed. ' + secs + ' seconds on the clock.');
                    focusFirst('.chip');
                }
                updateCta(cur); updateHint(cur);
            };

            // ---------- navigation ----------
            var doShow = function(name) {
                root.querySelectorAll('.stage').forEach(function(s) { s.classList.toggle('is-active', s.id === 'stage-' + name); });
                var cur = stepOf[name];
                root.querySelectorAll('.step').forEach(function(el, i) {
                    el.classList.toggle('is-active', i === cur); el.classList.toggle('is-done', i < cur);
                });
                if ($('app-content')) { $('app-content').scrollTop = 0; }
                if (name === 'question' && !started.question) { gate('q'); }
                if (name === 'coding' && !started.coding) { gate('code'); }
                updateCta(name); updateHint(name);
                if (name === 'crossword') { renderCrossword(); }
                if (name === 'review') { renderReview(); }
                if (name === 'results') { renderResults(); }
                if (userNavigated) {
                    var active = root.querySelector('.stage.is-active');
                    var h = active && active.querySelector('.stage__title');
                    if (h && h.focus) { h.focus(); }
                }
            };
            var show = function(name) { doShow(name); };

            var ctaLabel = function(name) {
                if (name === 'question' || name === 'coding') {
                    if (!started[name]) { return 'Start'; }
                    if (!submitted[name]) { return name === 'question' ? 'Confirm answer' : 'Submit code'; }
                    return 'Continue';
                }
                if (name === 'crossword') { return submitted[name] ? 'Continue' : 'Submit crossword'; }
                if (name === 'start') { return 'Start'; }
                if (name === 'review') { return 'See results'; }
                if (name === 'results') { return 'Finish'; }
                return 'Next';
            };
            var updateCta = function(name) {
                var cta = $('cta'); if (!cta) { return; }
                // The footer button is hidden on the results screen (the old "Finish" did nothing) and
                // while a timed game's Start gate is up — that gate carries its own Start button, so the
                // footer control only ever appears as the in-game submit/validation button.
                var gateWaiting = (name === 'question' || name === 'coding') && !started[name];
                cta.hidden = (name === 'results') || gateWaiting;
                if (cta.hidden) { return; }
                cta.textContent = ctaLabel(name);
                // Coding: keep Submit locked until every sequence + line has been revealed,
                // so an early click can't skip past unseen sequences.
                var lockCoding = name === 'coding' && started[name] && !submitted[name] && !codingAllRevealed();
                cta.disabled = lockCoding;
                cta.classList.toggle('is-locked', lockCoding);
                cta.title = lockCoding ? 'Reveal every sequence first — use the ▼ button' : '';
            };

            // The correct-sequence review screen exists only to show the answer, so it is skipped
            // entirely when Explain is off (rather than showing an empty screen).
            var explainOn = function() { return $('explain-toggle') ? $('explain-toggle').checked : true; };
            var advance = function() {
                var next = Math.min(idx + 1, flow.length - 1);
                if (flow[next] === 'review' && !explainOn()) { next = Math.min(next + 1, flow.length - 1); }
                idx = next; show(flow[idx]);
            };
            if ($('cta')) {
                $('cta').addEventListener('click', function() {
                    userNavigated = true;
                    var cur = flow[idx];
                    if ((cur === 'question' || cur === 'coding') && !started[cur]) { startGame(cur); return; }
                    if (isGame(cur) && !submitted[cur]) {
                        if (cur === 'coding' && !codingAllRevealed()) { announce('Reveal every sequence before submitting.'); return; }
                        doSubmit(cur); return;
                    }
                    advance();
                });
            }
            // Each timed game's Start gate has its own Start button, kept fully separate from the
            // footer submit/validation button — this is the only control that starts the game.
            [['start-q', 'question'], ['start-code', 'coding']].forEach(function(pair) {
                var b = $(pair[0]);
                if (b) { b.addEventListener('click', function() { userNavigated = true; startGame(pair[1]); }); }
            });

            // ---------- hint (one per attempt, server-issued; effect depends on the game) ----------
            var updateHint = function(name) {
                var btn = $('hint');
                if (!btn) { return; }
                var gated = (name === 'question' || name === 'coding') && !started[name];
                btn.disabled = !isGame(name) || hintUsed || !!submitted[name] || gated;
                // Say WHY it is disabled — the one hint is shared across the whole run.
                var why = !isGame(name) ? 'Hints are available during the games'
                    : hintUsed ? 'Your one hint is already used'
                    : submitted[name] ? 'This game is already submitted'
                    : gated ? 'Press Start first — your hint is still available'
                    : 'Hint (one per attempt)';
                btn.title = why;
                btn.setAttribute('aria-label', why);
            };
            if ($('hint')) {
                $('hint').addEventListener('click', function() {
                    var cur = flow[idx];
                    if (!isGame(cur) || hintUsed || submitted[cur]) { return; }
                    hintUsed = true;
                    updateHint(cur);
                    var payload = {};
                    if (cur === 'crossword') { payload.filled = filledCrossword(); }
                    if (cur === 'coding') { payload.seq = codeSeq; } // Hint the sequence in view.
                    call('mod_agon_get_hint', {cmid: CMID, game: cur, payload: JSON.stringify(payload)})
                        .then(function(res) {
                            // If the hint revealed nothing (e.g. grid already full), give it back.
                            if (!applyHint(cur, JSON.parse(res.hint))) { hintUsed = false; updateHint(cur); }
                            return res;
                        })
                        .catch(function(err) { hintUsed = false; updateHint(cur); Notification.exception(err); });
                });
            }
            var applyHint = function(cur, hint) {
                if (cur === 'crossword') {
                    // Reveal ceil(words/2) random letters straight onto the board.
                    var cells = hint.cells || [], any = false;
                    cells.forEach(function(cell) {
                        var m = cwMap[cell.rc];
                        if (m && m.input) { m.input.value = cell.letter; if (m.cell) { m.cell.classList.add('is-hint'); } any = true; }
                    });
                    if (any) { announce('Hint: revealed ' + cells.length + ' letter' + (cells.length === 1 ? '' : 's') + '.'); }
                    return any;
                } else if (cur === 'question') {
                    // 50/50: grey out + disable the wrong options the server chose.
                    var remove = hint.remove || [];
                    if (!remove.length) { return false; }
                    var qhost = $('q-host'); if (!qhost) { return false; }
                    remove.forEach(function(i) {
                        var opt = qhost.querySelector('.opt[data-i="' + i + '"]');
                        if (!opt) { return; }
                        opt.classList.add('is-eliminated', 'is-revealed');
                        opt.disabled = true;
                        if (opt.classList.contains('is-selected')) { opt.classList.remove('is-selected'); opt.setAttribute('aria-pressed', 'false'); }
                    });
                    announce('Hint: removed ' + remove.length + ' wrong answer' + (remove.length === 1 ? '' : 's') + '.');
                    return true;
                } else if (cur === 'coding') {
                    // Mark the correct option chips for the sequence in view.
                    var host = $('coding'), corrects = hint.corrects || [];
                    if (!host || !corrects.length) { return false; }
                    var marked = false;
                    host.querySelectorAll('.chip[data-seq="' + hint.seq + '"]').forEach(function(ch) {
                        if (corrects.indexOf(ch.dataset.v) !== -1) { ch.classList.add('is-correct'); marked = true; }
                    });
                    if (marked) { announce('Hint: the correct options are marked.'); }
                    return marked;
                }
                return false;
            };

            // ---------- header + stepper ----------
            if ($('a-sub')) { $('a-sub').textContent = (D.meta && (D.meta.topic || D.meta.subject)) || ''; }
            if ($('a-week')) { $('a-week').textContent = (D.meta && D.meta.week) ? ('Week ' + D.meta.week) : ''; }
            // "Explain" toggle (on by default) shows/hides the why-explanations on the
            // question feedback + coding review.
            if ($('explain-toggle') && $('agon-app')) {
                var applyExplain = function() {
                    $('agon-app').classList.toggle('show-explain', $('explain-toggle').checked);
                    // Turning Explain off while on the correct-sequence screen skips straight past it.
                    if (!$('explain-toggle').checked && flow[idx] === 'review') { advance(); }
                };
                $('explain-toggle').addEventListener('change', applyExplain);
                applyExplain();
            }
            // Testing mode: the results screen offers a "Play again" (reset attempt) button.
            if (D.testmode && $('testbar') && $('play-again')) {
                $('testbar').hidden = false;
                $('play-again').addEventListener('click', function() {
                    var sk = (window.M && window.M.cfg && window.M.cfg.sesskey) ? window.M.cfg.sesskey : '';
                    window.location.href = window.location.pathname + '?id=' + CMID + '&playagain=1&sesskey=' + encodeURIComponent(sk);
                });
            }
            // Timers depend on content (question options / coding lines) — fill the gates + pills.
            if (games.question) {
                var qsecs0 = questionSeconds();
                var qrb = root.querySelector('#ready-q .ready__big');
                if (qrb) { qrb.textContent = '⏱ ' + qsecs0 + 's'; }
                if ($('timer-q')) { $('timer-q').textContent = fmt(qsecs0); }
            }
            if (games.coding) {
                var codesecs = codingSeconds();
                var crb = root.querySelector('#ready-code .ready__big');
                if (crb) { crb.textContent = '⏱ ' + codesecs + 's'; }
                if ($('timer-code')) { $('timer-code').textContent = fmt(codesecs); }
            }
            if ($('stepper')) {
                $('stepper').innerHTML = steps.map(function(st) {
                    return '<div class="step"><span class="step__dot">' + st.icon + '</span>' + st.label + '</div>';
                }).join('');
            }

            var fb = function(id, ok, html) {
                var el = $(id); if (!el) { return; }
                el.className = 'feedback ' + (ok ? 'is-good' : 'is-bad'); el.innerHTML = html; el.hidden = false;
            };

            // ---------- submission dispatch ----------
            var collect = function(cur) {
                if (cur === 'crossword') {
                    var entries = {};
                    Object.keys(cwMap).forEach(function(k) { if (cwMap[k].input) { entries[k] = (cwMap[k].input.value || '').toUpperCase(); } });
                    return {entries: entries};
                }
                if (cur === 'question') {
                    var host = $('q-host'), sel = host && host.querySelector('.opt.is-selected');
                    return {selected: sel ? parseInt(sel.dataset.i, 10) : -1};
                }
                // coding: only the open sequence is still in the page; the rest live in
                // codingAnswers. Capture the open one, then read every sequence from the store.
                snapshotSequence(codeSeq);
                var answers = [];
                (D.coding.sequences || []).forEach(function(s, si) {
                    answers[si] = (codingAnswers[si] || []).slice();
                });
                return {answers: answers};
            };
            var doSubmit = function(cur) {
                // Guard synchronously: the countdown auto-submit and a click can race.
                if (submitted[cur] || submitting[cur]) { return; }
                submitting[cur] = true;
                if ($('cta')) { $('cta').disabled = true; }
                call('mod_agon_submit_game', {cmid: CMID, game: cur, payload: JSON.stringify(collect(cur))})
                    .then(function(resp) {
                        submitting[cur] = false;
                        submitted[cur] = true;
                        stopTimer();
                        scores.crossword = resp.scorecrossword;
                        scores.question = resp.scorequestion;
                        scores.coding = resp.scorecoding;
                        var fbk = resp.feedback ? JSON.parse(resp.feedback) : {};
                        feedback[cur] = fbk;
                        if (cur === 'crossword') { feedbackCrossword(fbk, resp.scorecrossword); }
                        if (cur === 'question') { feedbackQuestion(fbk, resp.scorequestion); }
                        if (cur === 'coding') { feedbackCoding(fbk, resp.scorecoding); }
                        if ($('cta')) { $('cta').disabled = false; }
                        updateCta(cur); updateHint(cur);
                        return resp;
                    })
                    .catch(function(err) {
                        submitting[cur] = false;
                        if ($('cta')) { $('cta').disabled = false; }
                        Notification.exception(err);
                    });
            };

            // ---------- CROSSWORD ----------
            function renderCrossword() {
                var host = $('cw'); if (!host || host.dataset.done) { return; }
                var words = (D.crossword && D.crossword.words) || [];
                var maxR = 0, maxC = 0, cells = {}, nums = {};
                cwMap = {};
                words.forEach(function(w) {
                    var len = w.length || 0;
                    for (var i = 0; i < len; i++) {
                        var r = w.direction === 'across' ? w.row : w.row + i;
                        var c = w.direction === 'across' ? w.col + i : w.col;
                        cells[r + '-' + c] = true; cwMap[r + '-' + c] = {};
                        maxR = Math.max(maxR, r); maxC = Math.max(maxC, c);
                    }
                    nums[w.row + '-' + w.col] = w.number;
                });
                host.style.setProperty('--cw-cols', maxC + 1);
                host.style.gridTemplateColumns = 'repeat(' + (maxC + 1) + ', var(--cw-size, 38px))';
                var html = '';
                for (var r = 0; r <= maxR; r++) {
                    for (var c = 0; c <= maxC; c++) {
                        html += cells[r + '-' + c]
                            ? '<div class="cw__cell" data-rc="' + r + '-' + c + '">' +
                                (nums[r + '-' + c] ? '<span class="cw__num">' + nums[r + '-' + c] + '</span>' : '') +
                                '<input maxlength="1" aria-label="Crossword cell row ' + (r + 1) + ' column ' + (c + 1) + '"></div>'
                            : '<div class="cw__cell" aria-hidden="true"></div>';
                    }
                }
                host.innerHTML = html; host.dataset.done = '1';
                host.querySelectorAll('.cw__cell[data-rc]').forEach(function(cell) {
                    var rc = cell.dataset.rc;
                    if (cwMap[rc]) { cwMap[rc].input = cell.querySelector('input'); cwMap[rc].cell = cell; }
                });
                wireCrosswordNav();
                // Direction marker next to each clue number: a long dash for across, a bar for down.
                var dirmark = function(dir) {
                    return dir === 'down'
                        ? '<span class="cw-dir" title="Down (vertical)">|</span>'
                        : '<span class="cw-dir" title="Across (horizontal)">&#8212;</span>';
                };
                var li = function(ws) {
                    return ws.map(function(w) {
                        return '<li data-wk="' + w.number + '-' + w.direction + '">' +
                            '<span class="clue-text"><b>' + w.number + '.</b> ' + dirmark(w.direction) + ' ' + esc(w.clue) + '</span>' +
                            '<span class="clue-answer" aria-live="polite"></span></li>';
                    }).join('');
                };
                if ($('clues')) {
                    $('clues').innerHTML =
                        '<h4>Across</h4><ol>' + li(words.filter(function(w) { return w.direction === 'across'; })) + '</ol>' +
                        '<h4>Down</h4><ol>' + li(words.filter(function(w) { return w.direction === 'down'; })) + '</ol>';
                }
            }
            // Keyboard flow across the grid: type advances along the current word,
            // arrow keys move (and set the direction), backspace steps back and clears.
            var cwAt = function(r, c) { return cwMap[r + '-' + c]; };
            var cwFocus = function(r, c) {
                var m = cwAt(r, c);
                if (m && m.input && !m.input.disabled) { m.input.focus(); m.input.select(); return true; }
                return false;
            };
            // Direction is sticky: it stays whatever it is until an arrow key (or focusing a
            // single-word cell) changes it. At a crossing we deliberately keep the current
            // direction, so typing a long word never bumps onto the small word that crosses it.
            var cwDirAt = function() { return cwActiveDir; };
            var cwHighlight = function(r, c) {
                root.querySelectorAll('.cw__cell.is-word').forEach(function(el) { el.classList.remove('is-word'); });
                var d = cwDirAt(), dr = d === 'down' ? 1 : 0, dc = d === 'across' ? 1 : 0;
                var sr = r, sc = c;
                while (cwAt(sr - dr, sc - dc)) { sr -= dr; sc -= dc; }
                for (var cr = sr, cc = sc; cwAt(cr, cc); cr += dr, cc += dc) {
                    var m = cwAt(cr, cc); if (m && m.cell) { m.cell.classList.add('is-word'); }
                }
            };
            function wireCrosswordNav() {
                Object.keys(cwMap).forEach(function(rc) {
                    var m = cwMap[rc]; if (!m.input) { return; }
                    var p = rc.split('-'), r = parseInt(p[0], 10), c = parseInt(p[1], 10);
                    m.input.addEventListener('focus', function() {
                        // Auto-pick a direction only when the cell belongs to a single word; at a
                        // crossing keep the sticky direction (only arrow keys switch it there).
                        var hasAcross = !!(cwAt(r, c - 1) || cwAt(r, c + 1));
                        var hasDown = !!(cwAt(r - 1, c) || cwAt(r + 1, c));
                        if (hasAcross && !hasDown) { cwActiveDir = 'across'; }
                        else if (hasDown && !hasAcross) { cwActiveDir = 'down'; }
                        cwHighlight(r, c);
                    });
                    m.input.addEventListener('input', function() {
                        var v = (m.input.value || '').toUpperCase().slice(0, 1);
                        m.input.value = v;
                        if (v) { var d = cwDirAt(); cwFocus(r + (d === 'down' ? 1 : 0), c + (d === 'across' ? 1 : 0)); }
                    });
                    m.input.addEventListener('keydown', function(e) {
                        switch (e.key) {
                            case 'ArrowRight': e.preventDefault(); cwActiveDir = 'across'; cwFocus(r, c + 1); break;
                            case 'ArrowLeft': e.preventDefault(); cwActiveDir = 'across'; cwFocus(r, c - 1); break;
                            case 'ArrowDown': e.preventDefault(); cwActiveDir = 'down'; cwFocus(r + 1, c); break;
                            case 'ArrowUp': e.preventDefault(); cwActiveDir = 'down'; cwFocus(r - 1, c); break;
                            case 'Backspace':
                                if (!m.input.value) {
                                    e.preventDefault();
                                    var d = cwDirAt(), pr = r - (d === 'down' ? 1 : 0), pc = c - (d === 'across' ? 1 : 0);
                                    if (cwFocus(pr, pc)) { var pm = cwAt(pr, pc); if (pm && pm.input) { pm.input.value = ''; } }
                                }
                                break;
                            default: break;
                        }
                    });
                });
            }
            var filledCrossword = function() {
                return Object.keys(cwMap).filter(function(k) { return cwMap[k].input && cwMap[k].input.value; });
            };
            function feedbackCrossword(fbk, score) {
                var solution = fbk.solution || {};
                var words = (D.crossword && D.crossword.words) || [];
                // Mark each filled cell right/wrong (per-cell visual cue on the grid).
                Object.keys(cwMap).forEach(function(k) {
                    var m = cwMap[k]; if (!m.input) { return; }
                    var val = (m.input.value || '').toUpperCase();
                    m.input.disabled = true;
                    if (val !== '') {
                        if (val === (solution[k] || '').toUpperCase()) { m.cell.classList.add('is-correct'); } else { m.cell.classList.add('is-wrong'); }
                    }
                });
                root.querySelectorAll('.cw__cell.is-word').forEach(function(el) { el.classList.remove('is-word'); });
                // Grade per WHOLE word (both modes): a word counts only if every one of its cells is
                // right. For words the student did not fully get, reveal the correct answer beside its
                // clue — CSS shows it only while the Explain toggle is on.
                var correctwords = 0;
                words.forEach(function(w) {
                    var len = w.length || 0, ok = len > 0, answer = '';
                    for (var i = 0; i < len; i++) {
                        var r = w.direction === 'across' ? w.row : w.row + i;
                        var c = w.direction === 'across' ? w.col + i : w.col;
                        var want = (solution[r + '-' + c] || '').toUpperCase();
                        answer += want;
                        var cellm = cwMap[r + '-' + c];
                        var got = (cellm && cellm.input) ? (cellm.input.value || '').toUpperCase() : '';
                        if (got === '' || got !== want) { ok = false; }
                    }
                    if (ok) { correctwords++; }
                    var slot = root.querySelector('.clues li[data-wk="' + w.number + '-' + w.direction + '"] .clue-answer');
                    if (slot) {
                        if (!ok && answer) { slot.textContent = answer; slot.classList.add('is-shown'); } else { slot.textContent = ''; slot.classList.remove('is-shown'); }
                    }
                });
                var allgood = words.length > 0 && correctwords === words.length;
                fb('fb-cw', allgood, (allgood ? '✓ All words correct! ' : '') + correctwords + ' of ' + words.length +
                    ' word' + (words.length === 1 ? '' : 's') + ' correct — <b>' + Number(score).toFixed(2) + '</b> points.');
            }

            // ---------- QUESTION ----------
            function renderQuestion() {
                var host = $('q-host'); if (!host) { return; }
                var q = (D.questions && D.questions[0]) || null;
                if (!q) { host.innerHTML = '<p class="lead">No question configured.</p>'; return; }
                if (host.dataset.done) { return; }
                host.innerHTML = '<div class="q__text">' + esc(q.question) + '</div>' +
                    '<p class="peek-hint" style="text-align:left;margin:2px 0 10px">&#128072; hover or tap an answer to reveal it</p>' +
                    q.options.map(function(o, i) {
                        return '<button class="opt peek" type="button" data-i="' + i + '" aria-pressed="false">' + esc(o) + '</button>';
                    }).join('');
                host.dataset.done = '1';
                host.querySelectorAll('.opt').forEach(function(o) {
                    o.addEventListener('click', function() {
                        if (submitted.question) { return; }
                        // Only the chosen option is clear; picking another re-blurs the last one.
                        host.querySelectorAll('.opt').forEach(function(x) {
                            x.classList.remove('is-selected', 'is-revealed'); x.setAttribute('aria-pressed', 'false');
                        });
                        o.classList.add('is-selected', 'is-revealed'); o.setAttribute('aria-pressed', 'true');
                    });
                });
            }
            function feedbackQuestion(fbk, score) {
                var host = $('q-host'); if (!host) { return; }
                var sel = host.querySelector('.opt.is-selected');
                var selIdx = sel ? parseInt(sel.dataset.i, 10) : -1;
                host.querySelectorAll('.opt').forEach(function(o) {
                    var i = parseInt(o.dataset.i, 10);
                    o.classList.add('is-revealed'); o.disabled = true;
                    if (i === fbk.correct) { o.classList.add('is-correct'); } else if (i === selIdx) { o.classList.add('is-wrong'); }
                });
                var ok = selIdx === fbk.correct;
                fb('fb-q', ok, (ok ? '✓ Correct! +' + Number(score).toFixed(2) : '✗ Not quite — the correct answer is highlighted.') +
                    (fbk.explanation ? '<span class="fb-exp">' + esc(fbk.explanation) + '</span>' : ''));
            }

            // ---------- CODING (each sequence lazy-loaded from the server) ----------
            var pick = null, dragging = null;
            var codingAnswers = {}; // seq index -> the student's blank values, kept as sequences unload
            var setPick = function(ch) {
                if (pick) { pick.classList.remove('is-sel'); pick.setAttribute('aria-pressed', 'false'); }
                pick = (pick === ch) ? null : ch;
                if (pick) { pick.classList.add('is-sel'); pick.setAttribute('aria-pressed', 'true'); }
            };
            var fillBlank = function(blank, chip) {
                blank.textContent = chip.dataset.v; blank.classList.add('is-filled'); blank.dataset.chip = chip.id;
                blank.setAttribute('aria-label', 'Blank, filled with ' + chip.dataset.v);
                chip.classList.add('is-used'); chip.classList.remove('is-sel', 'is-cued'); chip.setAttribute('aria-pressed', 'false');
                if (pick === chip) { pick = null; }
            };
            var clearBlank = function(blank) {
                var id = blank.dataset.chip, chip = id && $(id);
                if (chip) { chip.classList.remove('is-used'); }
                blank.textContent = '____'; blank.classList.remove('is-filled'); blank.removeAttribute('data-chip');
                blank.setAttribute('aria-label', 'Blank, empty');
            };
            // Snapshot a sequence's blanks into codingAnswers so its values survive the DOM unload.
            var snapshotSequence = function(si) {
                var host = $('coding'); if (!host) { return; }
                var arr = codingAnswers[si] || [];
                host.querySelectorAll('.seq[data-seq="' + si + '"] .blank').forEach(function(bl) {
                    arr[parseInt(bl.dataset.b, 10)] = bl.classList.contains('is-filled') ? bl.textContent : '';
                });
                codingAnswers[si] = arr;
            };
            // The reveal button: step this sequence's next line, else open the next sequence, else vanish.
            var updateSeqButton = function(seqEl) {
                var btn = seqEl.querySelector('.reveal-line'); if (!btn) { return; }
                var seqs = (D.coding && D.coding.sequences) || [];
                var si = parseInt(seqEl.dataset.seq, 10);
                if (seqEl.querySelector('.codeline.is-hidden')) {
                    // Say which line comes next, so the button reads as the way forward.
                    var all = seqEl.querySelectorAll('.codeline').length;
                    var shown = all - seqEl.querySelectorAll('.codeline.is-hidden').length;
                    btn.textContent = '▼ Reveal line ' + (shown + 1) + ' of ' + all;
                    btn.style.display = '';
                } else if (si < seqs.length - 1) {
                    btn.textContent = '▼ Reveal sequence ' + (si + 2) + ' of ' + seqs.length;
                    btn.style.display = '';
                } else {
                    btn.style.display = 'none';
                }
            };
            // Build the interactive code + chips for one loaded sequence into its card body.
            var fillSequence = function(card, si, data) {
                var body = card.querySelector('[data-body]'); if (!body) { return; }
                var bi = 0;
                var lines = (data.code || '').split('\n');
                var linehtml = lines.map(function(line, li) {
                    var lh = esc(line).replace(/____/g, function() {
                        var n = bi++;
                        return '<button type="button" class="blank" data-seq="' + si + '" data-b="' + n + '" aria-label="Blank, empty">____</button>';
                    });
                    return '<div class="codeline' + (li === 0 ? '' : ' is-hidden') + '">' + (lh || '&nbsp;') + '</div>';
                }).join('');
                var chips = (data.options || []).map(function(o, oi) {
                    return '<button type="button" class="chip" id="c' + si + '-' + oi + '" draggable="true" data-seq="' + si +
                        '" data-v="' + esc(o) + '" aria-pressed="false" aria-label="Option ' + esc(o) + '">' + esc(o) + '</button>';
                }).join('');
                var seqs = (D.coding && D.coding.sequences) || [];
                var needsBtn = lines.length > 1 || si < seqs.length - 1;
                var revealbtn = needsBtn ? '<button type="button" class="reveal-line" data-seq="' + si + '">▼ reveal next line</button>' : '';
                body.innerHTML = '<div class="code">' + linehtml + '</div>' + revealbtn +
                    '<div class="chips" role="group" aria-label="Options for sequence ' + (si + 1) + '">' + chips + '</div>';
                wireSequence(card);
                updateSeqButton(card);
            };
            // Attach the reveal / chip / blank handlers for the sequence rendered into this card.
            var wireSequence = function(card) {
                var rb = card.querySelector('.reveal-line');
                if (rb) {
                    rb.addEventListener('click', function() {
                        var si = parseInt(card.dataset.seq, 10);
                        if (card.querySelector('.codeline.is-hidden')) {
                            card.querySelectorAll('.codeline:not(.is-hidden) .blank').forEach(function(bl) { bl.classList.add('is-locked'); bl.disabled = true; });
                            card.querySelector('.codeline.is-hidden').classList.remove('is-hidden');
                            updateSeqButton(card);
                            announce('Next line revealed; earlier lines are now locked.');
                        } else {
                            advanceSeq(si);
                        }
                        updateCta('coding');
                    });
                }
                card.querySelectorAll('.chip').forEach(function(ch) {
                    ch.addEventListener('click', function() { if (!submitted.coding) { setPick(ch); } });
                    ch.addEventListener('dragstart', function() { dragging = ch; });
                    ch.addEventListener('dragend', function() { dragging = null; });
                });
                card.querySelectorAll('.blank').forEach(function(bl) {
                    bl.addEventListener('click', function() {
                        if (submitted.coding || bl.classList.contains('is-locked')) { return; }
                        if (bl.classList.contains('is-filled')) { clearBlank(bl); return; }
                        if (pick && pick.dataset.seq === bl.dataset.seq) { fillBlank(bl, pick); }
                    });
                    bl.addEventListener('dragover', function(e) {
                        if (!submitted.coding && !bl.classList.contains('is-locked') && dragging &&
                            dragging.dataset.seq === bl.dataset.seq && !dragging.classList.contains('is-used')) {
                            e.preventDefault(); bl.classList.add('drag-over');
                        }
                    });
                    bl.addEventListener('dragleave', function() { bl.classList.remove('drag-over'); });
                    bl.addEventListener('drop', function(e) {
                        e.preventDefault(); bl.classList.remove('drag-over');
                        if (submitted.coding || bl.classList.contains('is-locked')) { return; }
                        if (dragging && dragging.dataset.seq === bl.dataset.seq && !dragging.classList.contains('is-used')) {
                            if (bl.classList.contains('is-filled')) { clearBlank(bl); }
                            fillBlank(bl, dragging);
                        }
                    });
                });
            };
            // Pull a sequence's code + options from the server and render it live in its card.
            var loadSequence = function(si) {
                var host = $('coding'); if (!host) { return; }
                var card = host.querySelector('.seq[data-seq="' + si + '"]'); if (!card) { return; }
                card.classList.remove('seq--future');
                var body = card.querySelector('[data-body]');
                if (body) { body.innerHTML = '<div class="seq__note">Loading…</div>'; }
                call('mod_agon_get_sequence', {cmid: CMID, index: si})
                    .then(function(res) {
                        fillSequence(card, si, JSON.parse(res.sequence));
                        updateCta('coding');
                        var chip = card.querySelector('.chip'); if (chip && chip.focus) { chip.focus(); }
                        return res;
                    })
                    .catch(function(err) {
                        if (body) {
                            body.innerHTML = '<button type="button" class="reveal-line" data-retry="1">Could not load — retry</button>';
                            var r = body.querySelector('[data-retry]');
                            if (r) { r.addEventListener('click', function() { loadSequence(si); }); }
                        }
                        Notification.exception(err);
                    });
            };
            // Move on: bank the current sequence's answers, unload its code/options from the page
            // (only a locked placeholder is left — nothing to screenshot), then load the next.
            var advanceSeq = function(si) {
                var host = $('coding'); if (!host) { return; }
                var seqs = (D.coding && D.coding.sequences) || [];
                if (si >= seqs.length - 1) { return; }
                snapshotSequence(si);
                var curCard = host.querySelector('.seq[data-seq="' + si + '"]');
                if (curCard) {
                    curCard.classList.add('seq--past');
                    var body = curCard.querySelector('[data-body]');
                    if (body) { body.innerHTML = '<div class="seq__note">&#128274; Locked</div>'; }
                }
                codeSeq = si + 1;
                loadSequence(si + 1);
                announce('Sequence ' + (si + 2) + ' loaded. Earlier sequences are locked.');
            };
            // All sequences reached AND the last one fully shown → Submit may unlock.
            var codingAllRevealed = function() {
                var seqs = (D.coding && D.coding.sequences) || [];
                if (codeSeq < seqs.length - 1) { return false; }
                var host = $('coding');
                var cur = host && host.querySelector('.seq[data-seq="' + codeSeq + '"]');
                return !!(cur && cur.querySelector('.code') && !cur.querySelector('.codeline.is-hidden'));
            };
            function renderCoding() {
                var seqs = (D.coding && D.coding.sequences) || [];
                var host = $('coding'); if (!host || host.dataset.done) { return; }
                // Scaffold from metadata only; each card's code + options are pulled on open.
                host.innerHTML = seqs.map(function(s, si) {
                    return '<div class="seq' + (si === 0 ? '' : ' seq--future') + '" data-seq="' + si + '">' +
                        '<div class="seq__title">' + esc(s.title || ('Sequence ' + (si + 1))) + '</div>' +
                        '<div class="seq__body" data-body></div></div>';
                }).join('');
                host.dataset.done = '1';
                codeSeq = 0;
                codingAnswers = {};
                loadSequence(0);
            }
            function feedbackCoding(fbk, score) {
                // Only the last sequence is still in the page — score from the stored answers
                // + the revealed blanks, not the DOM. The review then re-renders every sequence.
                snapshotSequence(codeSeq);
                var seqs = fbk.sequences || [];
                var total = 0, correct = 0;
                seqs.forEach(function(s, si) {
                    var blanks = s.blanks || [], mine = codingAnswers[si] || [];
                    blanks.forEach(function(want, b) {
                        total++;
                        if ((mine[b] || '') === want) { correct++; }
                    });
                });
                fb('fb-code', correct === total, correct + ' of ' + total + ' blanks correct' +
                    (correct === total ? ' — perfect!' : '') + ' — <b>' + Number(score).toFixed(2) + '</b> points. See the correct code next.');
            }
            function renderReview() {
                var fbseqs = (feedback.coding && feedback.coding.sequences) || [];
                var host = $('review-body'); if (!host) { return; }
                host.innerHTML = fbseqs.map(function(s, si) {
                    var bi = 0, blanks = s.blanks || [], mine = codingAnswers[si] || [];
                    var code = esc(s.code || '').replace(/____/g, function() {
                        var b = bi++, want = blanks[b] != null ? blanks[b] : '____', got = mine[b] || '';
                        var okcls = (got !== '' && got === want) ? ' is-correct' : '';
                        return '<b class="rev-blank' + okcls + '">' + esc(want) + '</b>';
                    });
                    var exp = s.explanation || '';
                    return '<div class="review-seq"><div class="seq__title">' + esc(s.title || ('Sequence ' + (si + 1))) + '</div>' +
                        '<div class="code">' + code + '</div>' +
                        (exp ? '<p class="review-why"><b>Why:</b> ' + esc(exp) + '</p>' : '') + '</div>';
                }).join('');
            }

            // ---------- RESULTS ----------
            var countUp = function(el, target) {
                var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (reduce) { el.textContent = target.toFixed(2); return; }
                var t0 = null, dur = 700;
                var stepfn = function(ts) {
                    if (!t0) { t0 = ts; }
                    var p = Math.min(1, (ts - t0) / dur);
                    el.textContent = (target * p).toFixed(2);
                    if (p < 1) { requestAnimationFrame(stepfn); }
                };
                requestAnimationFrame(stepfn);
            };
            var paintScores = function(total) {
                var rows = '';
                [['crossword', 'Crossword'], ['question', 'Question'], ['coding', 'Coding']].forEach(function(d) {
                    if (games[d[0]]) {
                        var v = Number(scores[d[0]] || 0);
                        rows += '<div class="score-row"><span>' + d[1] + '</span><b>' + v.toFixed(2) + '</b></div>';
                    }
                });
                if ($('score-rows')) { $('score-rows').innerHTML = rows; }
                if ($('score-total')) { $('score-total').classList.add('pop'); countUp($('score-total'), Number(total)); }
                announce('Your score: ' + Number(total).toFixed(2) + ' points.');
            };
            var renderBoard = function(list) {
                if (!$('lb')) { return; }
                $('lb').innerHTML = (list || []).map(function(p, i) {
                    return '<li class="' + (p.me ? 'is-me' : '') + '"><span class="rank" aria-hidden="true">' + (i + 1) +
                        '</span><span class="who">' + esc(p.name) + '</span><span class="pts">' + Number(p.pts).toFixed(2) + '</span></li>';
                }).join('') || '<li class="lb-empty">No scores yet — be the first!</li>';
            };
            var fetchBoard = function() {
                call('mod_agon_get_leaderboard', {cmid: CMID})
                    .then(function(res) { renderBoard(res.leaderboard); return res; })
                    .catch(function(err) { Notification.exception(err); });
            };
            function renderResults() {
                var sum = (Number(scores.crossword || 0) + Number(scores.question || 0) + Number(scores.coding || 0));
                if (finished) { paintScores(sum); fetchBoard(); return; }
                call('mod_agon_finish_attempt', {cmid: CMID})
                    .then(function(resp) {
                        finished = true;
                        scores.crossword = resp.scorecrossword;
                        scores.question = resp.scorequestion;
                        scores.coding = resp.scorecoding;
                        paintScores(resp.score);
                        fetchBoard();
                        return resp;
                    })
                    .catch(function(err) { paintScores(sum); fetchBoard(); Notification.exception(err); });
            }

            // ---------- boot: open or resume the attempt ----------
            call('mod_agon_start_attempt', {cmid: CMID})
                .then(function(resp) {
                    var sub = resp.submittedgames || [];
                    scores.crossword = resp.scorecrossword;
                    scores.question = resp.scorequestion;
                    scores.coding = resp.scorecoding;
                    sub.forEach(function(g) { submitted[g] = true; });
                    // Restore the spent hint too, so a reload can't re-enable it.
                    hintUsed = (resp.hintsused || []).length > 0;

                    if (resp.state === 'finished') {
                        finished = true;
                        userNavigated = true;
                        idx = flow.length - 1;
                        show('results');
                    } else if (sub.length > 0) {
                        // Resume an in-progress run: skip submitted games, land on the first
                        // unfinished one (or the results if every game is already in).
                        var target = -1;
                        for (var i = 1; i < flow.length; i++) {
                            if (isGame(flow[i]) && !submitted[flow[i]]) { target = i; break; }
                        }
                        if (target === -1) { target = flow.length - 1; }
                        userNavigated = true;
                        idx = target;
                        show(flow[idx]);
                    } else {
                        show('start');
                    }
                    return resp;
                })
                .catch(function(err) { show('start'); Notification.exception(err); });
        }
    };
});
