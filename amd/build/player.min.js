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

            // Build the flow + stepper from the enabled games.
            var steps = [], flow = ['start'];
            if (games.crossword) { steps.push({label: 'Crossword', icon: '▦', stages: ['crossword']}); flow.push('crossword'); }
            if (games.question) { steps.push({label: 'Question', icon: '?', stages: ['question']}); flow.push('question'); }
            if (games.coding) { steps.push({label: 'Code', icon: '{ }', stages: ['coding', 'review']}); flow.push('coding', 'review'); }
            steps.push({label: 'Result', icon: '★', stages: ['results']}); flow.push('results');
            var stepOf = {start: 0};
            steps.forEach(function(st, i) { st.stages.forEach(function(sg) { stepOf[sg] = i; }); });

            var idx = 0, userNavigated = false, finished = false;
            var submitted = {}, hintUsed = {}, scores = {}, cwMap = {}, feedback = {};
            var isGame = function(n) { return n === 'crossword' || n === 'question' || n === 'coding'; };

            // ---------- timed games: Start gate + countdown ----------
            var started = {}, timerId = null;
            var fmt = function(s) { var m = Math.floor(s / 60), x = s % 60; return '⏱ ' + m + ':' + (x < 10 ? '0' : '') + x; };
            var stopTimer = function() { if (timerId) { clearInterval(timerId); timerId = null; } };
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
                    startTimer(10, $('timer-q'), function() { doSubmit('question'); });
                    announce('Question revealed. 10 seconds on the clock.');
                    focusFirst('.opt');
                } else if (cur === 'coding') {
                    if ($('ready-code')) { $('ready-code').hidden = true; }
                    if ($('coding')) { $('coding').hidden = false; }
                    if ($('timer-code')) { $('timer-code').hidden = false; }
                    renderCoding();
                    startTimer(45, $('timer-code'), function() { doSubmit('coding'); });
                    announce('Code revealed. 45 seconds on the clock.');
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
            var updateCta = function(name) { if ($('cta')) { $('cta').textContent = ctaLabel(name); } };

            if ($('cta')) {
                $('cta').addEventListener('click', function() {
                    userNavigated = true;
                    var cur = flow[idx];
                    if ((cur === 'question' || cur === 'coding') && !started[cur]) { startGame(cur); return; }
                    if (isGame(cur) && !submitted[cur]) { doSubmit(cur); return; }
                    idx = Math.min(idx + 1, flow.length - 1); show(flow[idx]);
                });
            }

            // ---------- hint (context-aware, once per game, server-issued) ----------
            var updateHint = function(name) {
                if (!$('hint')) { return; }
                $('hint').disabled = !isGame(name) || !!hintUsed[name] || !!submitted[name] ||
                    ((name === 'question' || name === 'coding') && !started[name]);
            };
            if ($('hint')) {
                $('hint').addEventListener('click', function() {
                    var cur = flow[idx];
                    if (!isGame(cur) || hintUsed[cur] || submitted[cur]) { return; }
                    hintUsed[cur] = true;
                    updateHint(cur);
                    var payload = {};
                    if (cur === 'crossword') { payload.filled = filledCrossword(); }
                    if (cur === 'coding') { payload.filled = filledCoding(); }
                    call('mod_agon_get_hint', {cmid: CMID, game: cur, payload: JSON.stringify(payload)})
                        .then(function(res) { applyHint(cur, JSON.parse(res.hint)); return res; })
                        .catch(function(err) { hintUsed[cur] = false; updateHint(cur); Notification.exception(err); });
                });
            }
            var applyHint = function(cur, hint) {
                if (cur === 'crossword' && hint.rc && cwMap[hint.rc] && cwMap[hint.rc].input) {
                    cwMap[hint.rc].input.value = hint.letter;
                    if (cwMap[hint.rc].cell) { cwMap[hint.rc].cell.classList.add('is-hint'); }
                    announce('Hint: revealed the letter ' + hint.letter + '.');
                } else if (cur === 'question') {
                    if ($('q-hint')) { $('q-hint').innerHTML = '<b>Hint.</b> ' + esc(hint.explanation || 'No hint available.'); $('q-hint').hidden = false; }
                } else if (cur === 'coding' && typeof hint.seq !== 'undefined') {
                    var host = $('coding');
                    var bl = host && host.querySelector('.blank[data-seq="' + hint.seq + '"][data-b="' + hint.b + '"]');
                    if (bl) { bl.classList.add('is-cued'); }
                    host.querySelectorAll('.chip[data-seq="' + hint.seq + '"]').forEach(function(ch) {
                        if (ch.dataset.v === hint.value && !ch.classList.contains('is-used')) { ch.classList.add('is-cued'); }
                    });
                    announce('Hint: the next blank takes ' + hint.value + '.');
                }
            };

            // ---------- header + stepper ----------
            if ($('a-sub')) { $('a-sub').textContent = (D.meta && (D.meta.topic || D.meta.subject)) || ''; }
            if ($('a-week')) { $('a-week').textContent = (D.meta && D.meta.week) ? ('Week ' + D.meta.week) : ''; }
            if ($('explain-toggle') && $('agon-app')) {
                $('explain-toggle').addEventListener('change', function() { $('agon-app').classList.toggle('show-explain', this.checked); });
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
                // coding
                var answers = [];
                (D.coding.sequences || []).forEach(function(s, si) {
                    answers[si] = [];
                    root.querySelectorAll('.blank[data-seq="' + si + '"]').forEach(function(bl) {
                        var b = parseInt(bl.dataset.b, 10);
                        answers[si][b] = bl.classList.contains('is-filled') ? bl.textContent : '';
                    });
                });
                return {answers: answers};
            };
            var doSubmit = function(cur) {
                if (submitted[cur]) { return; }
                if ($('cta')) { $('cta').disabled = true; }
                call('mod_agon_submit_game', {cmid: CMID, game: cur, payload: JSON.stringify(collect(cur))})
                    .then(function(resp) {
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
                    .catch(function(err) { if ($('cta')) { $('cta').disabled = false; } Notification.exception(err); });
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
                host.style.gridTemplateColumns = 'repeat(' + (maxC + 1) + ', 38px)';
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
                var li = function(ws) { return ws.map(function(w) { return '<li><b>' + w.number + '.</b> ' + esc(w.clue) + '</li>'; }).join(''); };
                if ($('clues')) {
                    $('clues').innerHTML =
                        '<h4>Across</h4><ol>' + li(words.filter(function(w) { return w.direction === 'across'; })) + '</ol>' +
                        '<h4>Down</h4><ol>' + li(words.filter(function(w) { return w.direction === 'down'; })) + '</ol>';
                }
            }
            var filledCrossword = function() {
                return Object.keys(cwMap).filter(function(k) { return cwMap[k].input && cwMap[k].input.value; });
            };
            function feedbackCrossword(fbk, score) {
                var solution = fbk.solution || {};
                var total = 0, correct = 0;
                Object.keys(cwMap).forEach(function(k) {
                    var m = cwMap[k]; if (!m.input) { return; }
                    total++;
                    var val = (m.input.value || '').toUpperCase();
                    m.input.disabled = true;
                    if (val !== '') {
                        if (val === (solution[k] || '').toUpperCase()) { m.cell.classList.add('is-correct'); correct++; } else { m.cell.classList.add('is-wrong'); }
                    }
                });
                fb('fb-cw', correct === total, (correct === total ? '✓ All correct! ' : '') + correct + ' of ' + total +
                    ' letters correct — <b>' + Number(score).toFixed(2) + '</b> points.');
            }

            // ---------- QUESTION ----------
            function renderQuestion() {
                var host = $('q-host'); if (!host) { return; }
                var q = (D.questions && D.questions[0]) || null;
                if (!q) { host.innerHTML = '<p class="lead">No question configured.</p>'; return; }
                if (host.dataset.done) { return; }
                host.innerHTML = '<div class="q__text">' + esc(q.question) + '</div>' +
                    '<p class="peek-hint" style="text-align:left;margin:2px 0 10px">&#128072; reveal an answer (hover, tap, or focus it)</p>' +
                    q.options.map(function(o, i) {
                        return '<button class="opt peek" type="button" data-i="' + i + '" aria-pressed="false">' + esc(o) + '</button>';
                    }).join('');
                host.dataset.done = '1';
                host.querySelectorAll('.opt').forEach(function(o) {
                    o.addEventListener('click', function() {
                        if (submitted.question) { return; }
                        o.classList.add('is-revealed');
                        host.querySelectorAll('.opt').forEach(function(x) { x.classList.remove('is-selected'); x.setAttribute('aria-pressed', 'false'); });
                        o.classList.add('is-selected'); o.setAttribute('aria-pressed', 'true');
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

            // ---------- CODING ----------
            var pick = null, dragging = null;
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
            function renderCoding() {
                var seqs = (D.coding && D.coding.sequences) || [];
                var host = $('coding'); if (!host || host.dataset.done) { return; }
                host.innerHTML = seqs.map(function(s, si) {
                    var bi = 0;
                    var lines = (s.code || '').split('\n');
                    var linehtml = lines.map(function(line, li) {
                        var lh = esc(line).replace(/____/g, function() {
                            var n = bi++;
                            return '<button type="button" class="blank" data-seq="' + si + '" data-b="' + n + '" aria-label="Blank, empty">____</button>';
                        });
                        return '<div class="codeline' + (li === 0 ? '' : ' is-hidden') + '">' + (lh || '&nbsp;') + '</div>';
                    }).join('');
                    var chips = (s.options || []).map(function(o, oi) {
                        return '<button type="button" class="chip" id="c' + si + '-' + oi + '" draggable="true" data-seq="' + si +
                            '" data-v="' + esc(o) + '" aria-pressed="false" aria-label="Option ' + esc(o) + '">' + esc(o) + '</button>';
                    }).join('');
                    var revealbtn = lines.length > 1 ? '<button type="button" class="reveal-line" data-seq="' + si + '" aria-label="Reveal next code line">▼ reveal next line</button>' : '';
                    return '<div class="seq"><div class="seq__title">' + esc(s.title || ('Sequence ' + (si + 1))) + '</div><div class="code">' +
                        linehtml + '</div>' + revealbtn + '<div class="chips" role="group" aria-label="Options for sequence ' + (si + 1) + '">' + chips + '</div></div>';
                }).join('');
                host.dataset.done = '1';
                host.querySelectorAll('.reveal-line').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var seqEl = btn.parentNode;
                        seqEl.querySelectorAll('.codeline:not(.is-hidden) .blank').forEach(function(bl) { bl.classList.add('is-locked'); bl.disabled = true; });
                        var next = seqEl.querySelector('.codeline.is-hidden');
                        if (next) { next.classList.remove('is-hidden'); }
                        if (!seqEl.querySelector('.codeline.is-hidden')) { btn.style.display = 'none'; }
                        announce('Next line revealed; earlier lines are now locked.');
                    });
                });
                host.querySelectorAll('.chip').forEach(function(ch) {
                    ch.addEventListener('click', function() { if (!submitted.coding) { setPick(ch); } });
                    ch.addEventListener('dragstart', function() { dragging = ch; });
                    ch.addEventListener('dragend', function() { dragging = null; });
                });
                host.querySelectorAll('.blank').forEach(function(bl) {
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
            }
            var filledCoding = function() {
                var keys = [];
                root.querySelectorAll('.blank.is-filled').forEach(function(bl) { keys.push(bl.dataset.seq + '-' + bl.dataset.b); });
                return keys;
            };
            function feedbackCoding(fbk, score) {
                var host = $('coding'); if (!host) { return; }
                var seqs = fbk.sequences || [];
                var total = 0, correct = 0;
                host.querySelectorAll('.blank').forEach(function(bl) {
                    total++; bl.disabled = true;
                    var seq = parseInt(bl.dataset.seq, 10), b = parseInt(bl.dataset.b, 10);
                    var want = (seqs[seq] && seqs[seq].blanks) ? seqs[seq].blanks[b] : undefined;
                    var got = bl.classList.contains('is-filled') ? bl.textContent : '';
                    if (got !== '') { if (got === want) { bl.classList.add('is-correct'); correct++; } else { bl.classList.add('is-wrong'); } }
                });
                fb('fb-code', correct === total, correct + ' of ' + total + ' blanks correct' +
                    (correct === total ? ' — perfect!' : '') + ' — <b>' + Number(score).toFixed(2) + '</b> points.');
            }
            function renderReview() {
                var seqs = (D.coding && D.coding.sequences) || [];
                var fbseqs = (feedback.coding && feedback.coding.sequences) || [];
                if ($('review-explain')) {
                    $('review-explain').textContent = fbseqs.map(function(s) { return s.explanation || ''; }).join(' ');
                }
                if ($('review-code')) {
                    $('review-code').innerHTML = seqs.map(function(s, si) {
                        var bi = 0, blanks = (fbseqs[si] && fbseqs[si].blanks) || [];
                        return esc(s.code || '').replace(/____/g, function() { var v = blanks[bi++]; return '<b>' + esc(v != null ? v : '____') + '</b>'; });
                    }).join('\n\n');
                }
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
            var paintResults = function(total) {
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
                if ($('lb')) {
                    $('lb').innerHTML = (D.leaderboard || []).slice().sort(function(a, b) { return b.pts - a.pts; })
                        .map(function(p, i) {
                            return '<li class="' + (p.me ? 'is-me' : '') + '"><span class="rank" aria-hidden="true">' + (i + 1) +
                                '</span><span class="who">' + esc(p.name) + '</span><span class="pts">' + p.pts.toFixed(2) + '</span></li>';
                        }).join('');
                }
            };
            function renderResults() {
                var fallback = (Number(scores.crossword || 0) + Number(scores.question || 0) + Number(scores.coding || 0));
                if (finished) { paintResults(fallback); return; }
                call('mod_agon_finish_attempt', {cmid: CMID})
                    .then(function(resp) {
                        finished = true;
                        scores.crossword = resp.scorecrossword;
                        scores.question = resp.scorequestion;
                        scores.coding = resp.scorecoding;
                        paintResults(resp.score);
                        return resp;
                    })
                    .catch(function(err) { paintResults(fallback); Notification.exception(err); });
            }

            // ---------- boot: open or resume the attempt ----------
            call('mod_agon_start_attempt', {cmid: CMID})
                .then(function(resp) {
                    if (resp.state === 'finished') {
                        finished = true;
                        scores.crossword = resp.scorecrossword;
                        scores.question = resp.scorequestion;
                        scores.coding = resp.scorecoding;
                        (resp.submittedgames || []).forEach(function(g) { submitted[g] = true; });
                        idx = flow.length - 1;
                        userNavigated = true;
                        show('results');
                    } else {
                        show('start');
                    }
                    return resp;
                })
                .catch(function(err) { show('start'); Notification.exception(err); });
        }
    };
});
