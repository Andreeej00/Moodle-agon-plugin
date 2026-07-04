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
 * Question bank authoring UI for mod_agon (teacher-side).
 *
 * One shared "Generate with AI" panel on top (source material via paste / Google
 * link / PDF-PPTX upload, and a Default-key or Custom-provider mode), then one
 * expandable panel per game with a Manual and a JSON editor, a per-game "how many
 * to generate" count, a crossword builder + live preview, and per-game Save.
 * Everything talks to the mod_agon web services.
 *
 * @module     mod_agon/bank
 * @copyright  2026 Andrej Micic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['mod_agon/crossword', 'core/ajax', 'core/notification'], function(CW, Ajax, Notification) {

    var uid = 0;
    var esc = function(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    };
    var elFrom = function(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    };
    var call = function(method, args) { return Ajax.call([{methodname: method, args: args}])[0]; };
    var pretty = function(obj) { return JSON.stringify(obj, null, 2); };
    var parseSafe = function(text) {
        try { var v = JSON.parse(text); return (v && typeof v === 'object') ? v : null; } catch (e) { return null; }
    };

    return {
        /**
         * Initialise the authoring screen.
         *
         * @param {Number} cmid The course module id, for the web services.
         */
        init: function(cmid) {
            var CMID = cmid;
            var D = window.AGON_BANK || {};
            var host = document.getElementById('bank-panels');
            if (!host) { return; }
            var live = document.getElementById('bank-live');
            var announce = function(m) { if (live) { live.textContent = ''; setTimeout(function() { live.textContent = m; }, 30); } };
            var mode = 'manual';

            // ---------- shared AI setup (top of the page) ----------
            var buildAiSetup = function() {
                var ai = D.ai || {};
                var top = document.getElementById('bank-ai-top');
                var wrap = elFrom(
                    '<details class="bank-ai bank-ai-top"><summary>&#10024; Generate with AI</summary>' +
                    '<div class="bank-ai-body">' +
                    '<div class="bank-airow">' +
                    '<label class="bank-radio"><input type="radio" name="aimode" value="default" checked> Default (my Gemini key)</label>' +
                    '<label class="bank-radio"><input type="radio" name="aimode" value="custom"> Custom provider / key</label>' +
                    '</div>' +
                    '<p class="bank-hint" data-defnote></p>' +
                    '<div class="bank-row bank-defmodel">' +
                    '<label class="bank-lbl" style="margin:0">Gemini model</label>' +
                    '<input type="text" class="bank-inp" data-defmodel placeholder="gemini-2.5-flash">' +
                    '</div>' +
                    '<div class="bank-custom" hidden>' +
                    '<div class="bank-row">' +
                    '<select class="bank-sel" data-provider><option value="google">Google (Gemini)</option>' +
                    '<option value="anthropic">Claude</option><option value="openai">ChatGPT</option></select>' +
                    '<input type="text" class="bank-inp" data-model placeholder="gemini-2.5-flash">' +
                    '<input type="password" class="bank-inp" data-key placeholder="your API key" autocomplete="off">' +
                    '</div>' +
                    '<p class="bank-hint">Model ID as shown in the provider console, e.g. <code>gemini-2.5-flash</code>. ' +
                    'Gemini: <code>gemini-2.5-flash, gemini-2.0-flash, gemini-1.5-pro</code> &middot; ' +
                    'Claude: <code>claude-sonnet-5, claude-opus-4-8</code> &middot; ChatGPT: <code>gpt-4o, gpt-4o-mini</code>. ' +
                    'Leave blank for the default.</p>' +
                    '</div>' +
                    '<label class="bank-lbl">Lecture material (used by every game below)</label>' +
                    '<div class="bank-row"><input type="url" class="bank-inp" data-url placeholder="Google Docs/Slides link (shared &ldquo;anyone with the link&rdquo;)">' +
                    '<button type="button" class="bank-btn bank-btn--sm" data-fetch>Read link</button></div>' +
                    '<div class="bank-row"><input type="file" class="bank-file" data-file accept=".pdf,.pptx">' +
                    '<button type="button" class="bank-btn bank-btn--sm" data-readfile>Read file</button></div>' +
                    '<textarea class="bank-ta" data-src rows="5" placeholder="&hellip;or paste lecture text here. A link or file fills this box."></textarea>' +
                    '<p class="bank-hint">Your material is sent to the chosen AI when you Generate. PDF text extraction is best-effort ' +
                    '(for scanned PDFs, upload a PPTX or paste text).</p>' +
                    '<span class="bank-ai-status" data-status></span>' +
                    '</div></details>'
                );
                top.appendChild(wrap);
                var q = function(sel) { return wrap.querySelector('[data-' + sel + ']'); };
                var status = function(m, c) { var s = q('status'); s.textContent = m || ''; s.className = 'bank-ai-status' + (c ? ' ' + c : ''); };
                var getMode = function() { var r = wrap.querySelector('input[name="aimode"]:checked'); return r ? r.value : 'default'; };
                q('defnote').innerHTML = ai.hasDefaultKey
                    ? 'Using the site default Gemini key. Pick any Gemini model below.'
                    : '&#9888; No default key set yet — add one (see below) or switch to Custom. &ldquo;Copy prompt&rdquo; works with no key.';
                q('defmodel').value = ai.defaultModel || 'gemini-2.5-flash';
                wrap.querySelectorAll('input[name="aimode"]').forEach(function(r) {
                    r.addEventListener('change', function() {
                        var custom = getMode() === 'custom';
                        wrap.querySelector('.bank-custom').hidden = !custom;
                        q('defnote').hidden = custom;
                        wrap.querySelector('.bank-defmodel').hidden = custom;
                    });
                });
                q('fetch').addEventListener('click', function() {
                    var url = q('url').value.trim();
                    if (!url) { status('Paste a link first.', 'is-err'); return; }
                    status('Reading link…');
                    call('mod_agon_fetch_source', {cmid: CMID, url: url})
                        .then(function(res) { q('src').value = res.text || ''; status('Loaded ' + (res.text || '').length + ' characters.', 'is-ok'); return res; })
                        .catch(function(err) { status((err && err.message) || 'Could not read that link.', 'is-err'); });
                });
                q('readfile').addEventListener('click', function() {
                    var f = q('file').files[0];
                    if (!f) { status('Choose a PDF or PPTX first.', 'is-err'); return; }
                    if (f.size > 20 * 1024 * 1024) { status('File too large (max 20 MB).', 'is-err'); return; }
                    status('Reading ' + f.name + '…');
                    var reader = new FileReader();
                    reader.onload = function() {
                        var b64 = String(reader.result).split(',')[1] || '';
                        call('mod_agon_extract_file', {cmid: CMID, filename: f.name, content: b64})
                            .then(function(res) { q('src').value = res.text || ''; status('Extracted ' + (res.text || '').length + ' characters from ' + f.name + '.', 'is-ok'); return res; })
                            .catch(function(err) { status((err && err.message) || 'Could not read that file.', 'is-err'); });
                    };
                    reader.onerror = function() { status('Could not read the file.', 'is-err'); };
                    reader.readAsDataURL(f);
                });
                return {
                    config: function() {
                        if (getMode() === 'custom') {
                            return {sourcetext: q('src').value, provider: q('provider').value, model: q('model').value.trim(), apikey: q('key').value};
                        }
                        return {sourcetext: q('src').value, provider: 'google', model: q('defmodel').value.trim(), apikey: ''};
                    }
                };
            };
            var aiSetup = buildAiSetup();

            // ---------- per-game count + Generate / Copy prompt row ----------
            // `sub` (optional) adds a second count — coding's lines-per-sequence.
            var genRow = function(game, label, def, min, max, applyFn, sub) {
                var subhtml = sub ? '<label class="bank-genlabel">' + esc(sub.label) +
                    ' <input type="number" class="bank-num" data-subcount value="' + sub.def + '" min="' + sub.min + '" max="' + sub.max + '"></label>' : '';
                var row = elFrom(
                    '<div class="bank-genrow">' +
                    '<label class="bank-genlabel">' + esc(label) + ' <input type="number" class="bank-num" data-count value="' + def + '" min="' + min + '" max="' + max + '"></label>' +
                    subhtml +
                    '<button type="button" class="bank-btn bank-btn--primary bank-btn--sm" data-gen>&#10024; Generate</button>' +
                    '<button type="button" class="bank-btn bank-btn--sm" data-copy>Copy prompt</button>' +
                    '<span class="bank-ai-status" data-status></span>' +
                    '<textarea class="bank-ta bank-promptout" data-promptout rows="6" readonly hidden aria-label="Prompt to paste into your AI"></textarea>' +
                    '</div>'
                );
                var num = row.querySelector('[data-count]');
                var subnum = sub ? row.querySelector('[data-subcount]') : null;
                var status = function(m, c) { var s = row.querySelector('[data-status]'); s.textContent = m || ''; s.className = 'bank-ai-status' + (c ? ' ' + c : ''); };
                var count = function() { return Math.max(min, Math.min(max, parseInt(num.value, 10) || def)); };
                var subcount = function() { return sub ? Math.max(sub.min, Math.min(sub.max, parseInt(subnum.value, 10) || sub.def)) : 0; };
                row.querySelector('[data-gen]').addEventListener('click', function() {
                    var cfg = aiSetup.config();
                    if (!(cfg.sourcetext || '').trim()) {
                        status('Add lecture material first (paste text, read a link, or upload a file).', 'is-err');
                        return;
                    }
                    var btn = row.querySelector('[data-gen]'); btn.disabled = true; status('Generating…');
                    call('mod_agon_ai_generate', {cmid: CMID, game: game, sourcetext: cfg.sourcetext,
                        provider: cfg.provider, model: cfg.model, apikey: cfg.apikey, count: count(), subcount: subcount()})
                        .then(function(res) {
                            btn.disabled = false;
                            var obj = parseSafe(res.content);
                            if (!obj) { status('The AI returned invalid JSON — try again.', 'is-err'); return res; }
                            applyFn(obj);
                            status('Generated — review below, then Save.', 'is-ok');
                            return res;
                        })
                        .catch(function(err) { btn.disabled = false; status((err && err.message) || 'Generation failed.', 'is-err'); });
                });
                row.querySelector('[data-copy]').addEventListener('click', function() {
                    var cfg = aiSetup.config();
                    status('Building prompt…');
                    call('mod_agon_ai_prompt', {cmid: CMID, game: game, sourcetext: cfg.sourcetext, count: count(), subcount: subcount()})
                        .then(function(res) {
                            var out = row.querySelector('[data-promptout]'); out.hidden = false; out.value = res.prompt || '';
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(res.prompt || '').then(
                                    function() { status('Copied — paste into your AI, then paste the JSON into the JSON box.', 'is-ok'); },
                                    function() { status('Prompt ready below — select and copy it.', 'is-ok'); });
                            } else { out.focus(); out.select(); status('Prompt ready below — select and copy it.', 'is-ok'); }
                            return res;
                        })
                        .catch(function(err) { status((err && err.message) || 'Could not build the prompt.', 'is-err'); });
                });
                return row;
            };

            // ---------- generic panel scaffold ----------
            var makePanel = function(game, title) {
                var enabled = !!(D.games && D.games[game]);
                var det = elFrom(
                    '<details class="bank-panel" data-game="' + game + '"' + (enabled ? ' open' : '') + '>' +
                    '<summary class="bank-phead"><span class="bank-ptitle">' + esc(title) + '</span>' +
                    '<span class="bank-pstatus" data-status>&mdash;</span>' +
                    (enabled ? '' : '<span class="bank-poff">off in settings</span>') +
                    '</summary><div class="bank-pbody"></div></details>'
                );
                host.appendChild(det);
                return {el: det, body: det.querySelector('.bank-pbody'), status: det.querySelector('[data-status]')};
            };

            var saveRow = function(game, getContent, onSaved) {
                var row = elFrom('<div class="bank-save-row"><button type="button" class="bank-btn bank-btn--primary">Save ' +
                    esc(game) + '</button><span class="bank-save-status" data-save></span></div>');
                var btn = row.querySelector('button'), st = row.querySelector('[data-save]');
                btn.addEventListener('click', function() {
                    var content = getContent(st);
                    if (content === null) { return; } // getContent already reported why.
                    btn.disabled = true; st.textContent = 'Saving…'; st.className = 'bank-save-status';
                    call('mod_agon_save_content', {cmid: CMID, game: game, content: JSON.stringify(content)})
                        .then(function(res) {
                            btn.disabled = false; st.textContent = 'Saved.'; st.className = 'bank-save-status is-ok';
                            announce(game + ' saved.');
                            if (onSaved) { onSaved(); }
                            return res;
                        })
                        .catch(function(err) {
                            btn.disabled = false;
                            st.textContent = (err && err.message) || 'Save failed.'; st.className = 'bank-save-status is-err';
                        });
                });
                return {row: row, status: st};
            };

            // =================== CROSSWORD ===================
            var makeCrossword = function() {
                var p = makePanel('crossword', 'Crossword');
                var cur = 'manual';
                var body = elFrom(
                    '<div>' +
                    '<div class="bank-manual">' +
                    '<div data-rows></div>' +
                    '<button type="button" class="bank-btn bank-btn--sm" data-add>+ Add word</button>' +
                    '</div>' +
                    '<div class="bank-json" hidden>' +
                    '<label class="bank-lbl">Crossword JSON &mdash; <code>{ "grading": "custom|regular", "words": [ { "word", "clue" } ] }</code></label>' +
                    '<textarea class="bank-ta" data-json rows="10"></textarea>' +
                    '</div>' +
                    '<div class="bank-grading">' +
                    '<label><input type="radio" name="cwg-' + (++uid) + '" value="custom" checked><span><b>Custom scoring</b> (default)' +
                    '<small>Full solve ranks 1st&ndash;3rd = 1.0, 4th&ndash;10th = 0.75, later = 0.5; partial capped below 0.5.</small></span></label>' +
                    '<label><input type="radio" name="cwg-' + uid + '" value="regular"><span><b>Regular scoring</b>' +
                    '<small>Full solve = 1.0; partial = fraction of whole words correct (e.g. 4 of 10 = 0.40).</small></span></label>' +
                    '</div>' +
                    '<div class="bank-row"><button type="button" class="bank-btn" data-build>Build &amp; preview</button></div>' +
                    '<div class="cwp-wrap" data-preview></div>' +
                    '<div data-alerts></div>' +
                    '</div>'
                );
                p.body.appendChild(body);
                var rowsEl = body.querySelector('[data-rows]'), jsonEl = body.querySelector('[data-json]');
                var manualEl = body.querySelector('.bank-manual'), jsonWrap = body.querySelector('.bank-json');
                var previewEl = body.querySelector('[data-preview]'), alertsEl = body.querySelector('[data-alerts]');
                var gradingName = body.querySelector('.bank-grading input').name;

                var getGrading = function() { var r = body.querySelector('input[name="' + gradingName + '"]:checked'); return r ? r.value : 'custom'; };
                var setGrading = function(g) { body.querySelectorAll('input[name="' + gradingName + '"]').forEach(function(r) { r.checked = (r.value === g); }); };

                var addRow = function(word, clue) {
                    var r = elFrom('<div class="bank-row"><input class="bank-inp" data-w placeholder="WORD" style="max-width:150px" value="' +
                        esc(word || '') + '"><input class="bank-inp" data-c placeholder="Clue" value="' + esc(clue || '') + '">' +
                        '<button type="button" class="bank-x" title="Remove">&times;</button></div>');
                    r.querySelector('.bank-x').addEventListener('click', function() { r.remove(); });
                    rowsEl.appendChild(r);
                };
                var manualWords = function() {
                    var out = [];
                    rowsEl.querySelectorAll('.bank-row').forEach(function(r) {
                        out.push({word: r.querySelector('[data-w]').value, clue: r.querySelector('[data-c]').value});
                    });
                    return out;
                };
                var authoring = function() {
                    if (cur === 'json') { var o = parseSafe(jsonEl.value); return o || {words: [], grading: getGrading()}; }
                    return {words: manualWords(), grading: getGrading()};
                };
                var renderManual = function(obj) {
                    rowsEl.innerHTML = '';
                    var words = (obj && obj.words) || [];
                    if (!words.length) { addRow('', ''); addRow('', ''); addRow('', ''); }
                    else { words.forEach(function(w) { addRow(w.word, w.clue); }); }
                    setGrading((obj && obj.grading) === 'regular' ? 'regular' : 'custom');
                };

                var renderPreview = function(built) {
                    var g = built.grid, nums = {};
                    built.words.forEach(function(w) { nums[w.row + ',' + w.col] = w.number; });
                    var html = '';
                    if (g.rows > 0) {
                        html = '<div class="cwp" style="grid-template-columns:repeat(' + g.cols + ',22px)">';
                        for (var r = 0; r < g.rows; r++) {
                            for (var c = 0; c < g.cols; c++) {
                                var ltr = g.cells[r + ',' + c];
                                html += ltr
                                    ? '<div class="cwp-cell">' + (nums[r + ',' + c] ? '<span class="cwp-num">' + nums[r + ',' + c] + '</span>' : '') + esc(ltr) + '</div>'
                                    : '<div class="cwp-cell is-blank"></div>';
                            }
                        }
                        html += '</div>';
                    }
                    previewEl.innerHTML = html;
                    var a = '';
                    if (built.errors.length) { a += '<div class="bank-alert is-err"><b>Fix:</b> ' + built.errors.map(esc).join(' ') + '</div>'; }
                    if (built.unplaceable.length) {
                        a += '<div class="bank-alert is-warn"><b>Not placed:</b> ' + built.unplaceable.map(function(u) {
                            return esc(u.word) + ' (' + esc(u.reason) + ')';
                        }).join('; ') + '</div>';
                    }
                    if (built.ok) { a += '<div class="bank-alert is-ok">&#10003; ' + built.words.length + ' words placed.</div>'; }
                    alertsEl.innerHTML = a;
                };
                var build = function() { var b = CW.build(authoring().words); renderPreview(b); return b; };

                body.querySelector('[data-add]').addEventListener('click', function() { addRow('', ''); });
                body.querySelector('[data-build]').addEventListener('click', build);

                var applyGen = function(obj) {
                    // AI returns authoring {words:[{word,clue}], grading}; show it and build a preview.
                    jsonEl.value = pretty(obj);
                    renderManual(obj);
                    build();
                };
                p.body.insertBefore(genRow('crossword', 'Words to generate', 8, 3, 14, applyGen), p.body.firstChild);

                var updateStatus = function() {
                    var n = authoring().words.filter(function(w) { return (w.word || '').trim(); }).length;
                    p.status.textContent = n ? (n + ' words') : 'empty';
                    p.status.classList.toggle('is-empty', !n);
                };
                var sv = saveRow('crossword', function(st) {
                    var b = build();
                    if (!b.ok) {
                        st.textContent = b.errors.length ? 'Fix the words first.' : (b.unplaceable.length ? 'Some words can’t be placed.' : 'Add at least 3 words.');
                        st.className = 'bank-save-status is-err';
                        return null;
                    }
                    return {grading: getGrading(), words: b.words};
                }, updateStatus);
                p.body.appendChild(sv.row);

                // Prefill from saved layout (map built words -> {word, clue}).
                var saved = D.crossword || {};
                var initWords = (saved.words || []).map(function(w) { return {word: w.word, clue: w.clue}; });
                var initObj = initWords.length ? {words: initWords, grading: saved.grading} : null;
                renderManual(initObj);
                jsonEl.value = pretty(initObj || {grading: 'custom', words: [{word: 'TOKEN', clue: 'Smallest unit of text after splitting'}]});
                updateStatus();

                return {
                    setMode: function(m) {
                        if (m === 'json' && cur === 'manual') { jsonEl.value = pretty(authoring()); }
                        else if (m === 'manual' && cur === 'json') { var o = parseSafe(jsonEl.value); if (o) { renderManual(o); } }
                        cur = m; manualEl.hidden = (m !== 'manual'); jsonWrap.hidden = (m !== 'json');
                    }
                };
            };

            // =================== QUESTIONS ===================
            var makeQuestion = function() {
                var p = makePanel('question', 'Weekly question');
                var cur = 'manual';
                var body = elFrom(
                    '<div><div class="bank-manual"><div data-rows></div>' +
                    '<button type="button" class="bank-btn bank-btn--sm" data-add>+ Add question</button></div>' +
                    '<div class="bank-json" hidden><label class="bank-lbl">Questions JSON &mdash; ' +
                    '<code>{ "questions": [ { "question", "options":[…], "correct":index, "explanation" } ] }</code></label>' +
                    '<textarea class="bank-ta" data-json rows="12"></textarea></div></div>'
                );
                p.body.appendChild(body);
                var rowsEl = body.querySelector('[data-rows]'), jsonEl = body.querySelector('[data-json]');
                var manualEl = body.querySelector('.bank-manual'), jsonWrap = body.querySelector('.bank-json');

                var addOption = function(listEl, group, value, correct) {
                    var o = elFrom('<div class="bank-opt"><input type="radio" name="' + group + '"' + (correct ? ' checked' : '') +
                        ' title="Mark correct"><input class="bank-inp" data-o placeholder="Option" value="' + esc(value || '') + '">' +
                        '<button type="button" class="bank-x" title="Remove option">&times;</button></div>');
                    o.querySelector('.bank-x').addEventListener('click', function() { o.remove(); });
                    listEl.appendChild(o);
                };
                var addQuestion = function(q) {
                    q = q || {};
                    var group = 'qc-' + (++uid);
                    var item = elFrom(
                        '<div class="bank-item"><div class="bank-item-head"><input class="bank-inp" data-q placeholder="Question" value="' +
                        esc(q.question || '') + '"><button type="button" class="bank-x" title="Remove question">&times;</button></div>' +
                        '<div data-opts></div><button type="button" class="bank-btn bank-btn--sm" data-addopt>+ option</button>' +
                        '<label class="bank-lbl">Explanation</label><input class="bank-inp" data-exp placeholder="Why the answer is correct" value="' +
                        esc(q.explanation || '') + '"></div>'
                    );
                    var opts = item.querySelector('[data-opts]');
                    var options = q.options || ['', '', '', ''];
                    options.forEach(function(v, i) { addOption(opts, group, v, i === (q.correct || 0)); });
                    item.querySelector('[data-addopt]').addEventListener('click', function() { addOption(opts, group, '', false); });
                    item.querySelector('.bank-item-head .bank-x').addEventListener('click', function() { item.remove(); });
                    rowsEl.appendChild(item);
                };
                var manualQuestions = function() {
                    var out = [];
                    rowsEl.querySelectorAll('.bank-item').forEach(function(item) {
                        var opts = [], correct = 0, i = 0;
                        item.querySelectorAll('.bank-opt').forEach(function(o) {
                            opts.push(o.querySelector('[data-o]').value);
                            if (o.querySelector('input[type=radio]').checked) { correct = i; }
                            i++;
                        });
                        out.push({
                            question: item.querySelector('[data-q]').value,
                            options: opts, correct: correct,
                            explanation: item.querySelector('[data-exp]').value
                        });
                    });
                    return out;
                };
                var content = function() {
                    if (cur === 'json') { return parseSafe(jsonEl.value) || {questions: []}; }
                    return {questions: manualQuestions()};
                };
                var renderManual = function(obj) {
                    rowsEl.innerHTML = '';
                    var qs = (obj && obj.questions) || [];
                    if (!qs.length) { addQuestion(); } else { qs.forEach(addQuestion); }
                };

                body.querySelector('[data-add]').addEventListener('click', function() { addQuestion(); });
                p.body.insertBefore(genRow('question', 'Questions to generate', 5, 1, 20, function(obj) {
                    jsonEl.value = pretty(obj); renderManual(obj);
                }, {label: 'Answers per question', def: 4, min: 3, max: 6}), p.body.firstChild);

                var updateStatus = function() {
                    var n = (content().questions || []).filter(function(q) { return (q.question || '').trim(); }).length;
                    p.status.textContent = n ? (n + (n === 1 ? ' question' : ' questions')) : 'empty';
                    p.status.classList.toggle('is-empty', !n);
                };
                var sv = saveRow('question', function(st) {
                    var c = content();
                    if (!c || !(c.questions || []).length) { st.textContent = 'Add a question first.'; st.className = 'bank-save-status is-err'; return null; }
                    return c;
                }, updateStatus);
                p.body.appendChild(sv.row);

                var saved = D.question || {};
                var initObj = (saved.questions && saved.questions.length) ? saved
                    : {questions: [{question: 'Which process splits text into tokens?', options: ['Tokenization', 'Lemmatization', 'Normalization', 'Vectorization'], correct: 0, explanation: 'Tokenization breaks text into tokens.'}]};
                renderManual(initObj);
                jsonEl.value = pretty(initObj);
                updateStatus();

                return {
                    setMode: function(m) {
                        if (m === 'json' && cur === 'manual') { jsonEl.value = pretty(content()); }
                        else if (m === 'manual' && cur === 'json') { var o = parseSafe(jsonEl.value); if (o) { renderManual(o); } }
                        cur = m; manualEl.hidden = (m !== 'manual'); jsonWrap.hidden = (m !== 'json');
                    }
                };
            };

            // =================== CODING ===================
            var makeCoding = function() {
                var p = makePanel('coding', 'Coding');
                var cur = 'manual';
                var body = elFrom(
                    '<div><div class="bank-manual"><div data-rows></div>' +
                    '<button type="button" class="bank-btn bank-btn--sm" data-add>+ Add sequence</button></div>' +
                    '<div class="bank-json" hidden><label class="bank-lbl">Coding JSON &mdash; ' +
                    '<code>{ "sequences": [ { "title", "code" (use ____), "blanks":[…], "options":[…], "explanation" } ] }</code></label>' +
                    '<textarea class="bank-ta" data-json rows="12"></textarea></div></div>'
                );
                p.body.appendChild(body);
                var rowsEl = body.querySelector('[data-rows]'), jsonEl = body.querySelector('[data-json]');
                var manualEl = body.querySelector('.bank-manual'), jsonWrap = body.querySelector('.bank-json');
                var csv = function(a) { return (a || []).join(', '); };
                var splitCsv = function(s) { return String(s || '').split(',').map(function(x) { return x.trim(); }).filter(Boolean); };

                var addSeq = function(s) {
                    s = s || {};
                    var item = elFrom(
                        '<div class="bank-item"><div class="bank-item-head"><input class="bank-inp" data-t placeholder="Sequence title" value="' +
                        esc(s.title || '') + '"><button type="button" class="bank-x" title="Remove sequence">&times;</button></div>' +
                        '<label class="bank-lbl">Code — one step per line, use <code>____</code> for each blank (avoid blank lines)</label>' +
                        '<textarea class="bank-ta" data-code rows="4" placeholder="tokens = text.____()">' + esc(s.code || '') + '</textarea>' +
                        '<label class="bank-lbl">Blank answers, in order (comma-separated)</label>' +
                        '<input class="bank-inp" data-blanks placeholder="split, lower" value="' + esc(csv(s.blanks)) + '">' +
                        '<label class="bank-lbl">Options offered (comma-separated)</label>' +
                        '<input class="bank-inp" data-opts placeholder="split, lower, len, join, strip" value="' + esc(csv(s.options)) + '">' +
                        '<label class="bank-lbl">Explanation (why these answers fit)</label>' +
                        '<input class="bank-inp" data-exp placeholder="Why the answers are the suitable choices" value="' + esc(s.explanation || '') + '"></div>'
                    );
                    item.querySelector('.bank-item-head .bank-x').addEventListener('click', function() { item.remove(); });
                    rowsEl.appendChild(item);
                };
                var manualSeqs = function() {
                    var out = [];
                    rowsEl.querySelectorAll('.bank-item').forEach(function(item) {
                        out.push({
                            title: item.querySelector('[data-t]').value,
                            code: item.querySelector('[data-code]').value,
                            blanks: splitCsv(item.querySelector('[data-blanks]').value),
                            options: splitCsv(item.querySelector('[data-opts]').value),
                            explanation: item.querySelector('[data-exp]').value
                        });
                    });
                    return out;
                };
                var content = function() {
                    if (cur === 'json') { return parseSafe(jsonEl.value) || {sequences: []}; }
                    return {sequences: manualSeqs()};
                };
                var renderManual = function(obj) {
                    rowsEl.innerHTML = '';
                    var seqs = (obj && obj.sequences) || [];
                    if (!seqs.length) { addSeq(); } else { seqs.forEach(addSeq); }
                };

                body.querySelector('[data-add]').addEventListener('click', function() { addSeq(); });
                p.body.insertBefore(genRow('coding', 'Sequences to generate', 2, 1, 6, function(obj) {
                    jsonEl.value = pretty(obj); renderManual(obj);
                }, {label: 'Lines per sequence', def: 1, min: 1, max: 5}), p.body.firstChild);

                var updateStatus = function() {
                    var n = (content().sequences || []).filter(function(s) { return (s.code || '').trim(); }).length;
                    p.status.textContent = n ? (n + (n === 1 ? ' sequence' : ' sequences')) : 'empty';
                    p.status.classList.toggle('is-empty', !n);
                };
                var sv = saveRow('coding', function(st) {
                    var c = content();
                    if (!c || !(c.sequences || []).length) { st.textContent = 'Add a sequence first.'; st.className = 'bank-save-status is-err'; return null; }
                    return c;
                }, updateStatus);
                p.body.appendChild(sv.row);

                var saved = D.coding || {};
                var initObj = (saved.sequences && saved.sequences.length) ? saved
                    : {sequences: [{title: 'Tokenization', code: 'tokens = text.____()', blanks: ['split'], options: ['split', 'lower', 'len', 'join', 'strip'], explanation: 'str.split() breaks the text into a list of tokens.'}]};
                renderManual(initObj);
                jsonEl.value = pretty(initObj);
                updateStatus();

                return {
                    setMode: function(m) {
                        if (m === 'json' && cur === 'manual') { jsonEl.value = pretty(content()); }
                        else if (m === 'manual' && cur === 'json') { var o = parseSafe(jsonEl.value); if (o) { renderManual(o); } }
                        cur = m; manualEl.hidden = (m !== 'manual'); jsonWrap.hidden = (m !== 'json');
                    }
                };
            };

            var controllers = [makeCrossword(), makeQuestion(), makeCoding()];

            // Top toggle: switch every panel between Manual and JSON.
            document.querySelectorAll('.bank-segbtn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    mode = btn.dataset.mode;
                    document.querySelectorAll('.bank-segbtn').forEach(function(b) {
                        var on = (b === btn); b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
                    });
                    controllers.forEach(function(c) { c.setMode(mode); });
                });
            });
            controllers.forEach(function(c) { c.setMode(mode); });
        }
    };
});
