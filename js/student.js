/* mod_agon student play-flow (plain JS). Reads window.AGON (saved config, or example fallback).
   Accessible: every control is a real focusable element, ARIA labels + live-region announcements,
   focus moves to the active screen heading, blurred options reveal on focus. Answers are checked
   client-side here only (frontend skeleton) — real scoring moves server-side in Phase 2. */
(function(){
  var D = window.AGON;
  var dataEl = document.getElementById('agon-data');
  if (!D && dataEl) { try { D = JSON.parse(dataEl.textContent); } catch (e) {} }
  if (!D) { if (window.console) { console.error('Agon: no data (window.AGON missing)'); } return; }

  function $(id){ return document.getElementById(id); }
  var root = $('agon-app') || document;
  var games = D.enabledGames || { crossword:true, question:true, coding:true };
  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function announce(msg){ var el = $('agon-live'); if (el) { el.textContent = ''; setTimeout(function(){ el.textContent = msg; }, 30); } }

  // Build the flow + stepper from the enabled games.
  var steps = [], flow = ['start'];
  if (games.crossword) { steps.push({label:'Crossword', icon:'▦', stages:['crossword']}); flow.push('crossword'); }
  if (games.question)  { steps.push({label:'Question', icon:'?', stages:['question']}); flow.push('question'); }
  if (games.coding)    { steps.push({label:'Code', icon:'{ }', stages:['coding','review']}); flow.push('coding','review'); }
  steps.push({label:'Result', icon:'★', stages:['results']}); flow.push('results');
  var stepOf = { start:0 };
  steps.forEach(function(st, i){ st.stages.forEach(function(sg){ stepOf[sg] = i; }); });

  var idx = 0, userNavigated = false;
  var submitted = {}, hintUsed = {}, scores = {}, cwMap = {};
  function isGame(n){ return n === 'crossword' || n === 'question' || n === 'coding'; }

  // ---------- timed games: Start gate + countdown ----------
  var started = {}, timerId = null;
  function fmt(s){ var m = Math.floor(s/60), x = s%60; return '⏱ ' + m + ':' + (x<10?'0':'') + x; }
  function stopTimer(){ if (timerId) { clearInterval(timerId); timerId = null; } }
  function startTimer(seconds, el, onEnd){
    stopTimer();
    var rem = seconds;
    if (el) { el.textContent = fmt(rem); el.classList.remove('is-low'); }
    timerId = setInterval(function(){
      rem--;
      if (el) { el.textContent = fmt(rem); if (rem <= 5) { el.classList.add('is-low'); } }
      if (rem <= 0) { stopTimer(); announce("Time's up."); if (onEnd) { onEnd(); } }
    }, 1000);
  }
  function gate(key){
    if ($('ready-'+key)) { $('ready-'+key).hidden = false; }
    if ($('timer-'+key)) { $('timer-'+key).hidden = true; }
    if (key==='q' && $('q-host')) { $('q-host').hidden = true; }
    if (key==='code' && $('coding')) { $('coding').hidden = true; }
  }
  function focusFirst(sel){ var el = root.querySelector('.stage.is-active ' + sel); if (el && el.focus) { el.focus(); } }
  function startGame(cur){
    started[cur] = true;
    if (cur==='question') {
      if ($('ready-q')) { $('ready-q').hidden = true; }
      if ($('q-host')) { $('q-host').hidden = false; }
      if ($('timer-q')) { $('timer-q').hidden = false; }
      renderQuestion();
      startTimer(10, $('timer-q'), function(){ submitGame('question'); });
      announce('Question revealed. 10 seconds on the clock.');
      focusFirst('.opt');
    } else if (cur==='coding') {
      if ($('ready-code')) { $('ready-code').hidden = true; }
      if ($('coding')) { $('coding').hidden = false; }
      if ($('timer-code')) { $('timer-code').hidden = false; }
      renderCoding();
      startTimer(45, $('timer-code'), function(){ submitGame('coding'); });
      announce('Code revealed. 45 seconds on the clock.');
      focusFirst('.chip');
    }
    updateCta(cur); updateHint(cur);
  }
  function submitGame(cur){
    if (submitted[cur]) { return; }
    submitted[cur] = true;
    stopTimer();
    grade(cur);
    updateCta(cur); updateHint(cur);
  }

  // ---------- navigation ----------
  function doShow(name){
    root.querySelectorAll('.stage').forEach(function(s){ s.classList.toggle('is-active', s.id === 'stage-'+name); });
    var cur = stepOf[name];
    root.querySelectorAll('.step').forEach(function(el,i){ el.classList.toggle('is-active', i===cur); el.classList.toggle('is-done', i<cur); });
    if ($('app-content')) { $('app-content').scrollTop = 0; }
    if (name==='question' && !started.question) { gate('q'); }
    if (name==='coding' && !started.coding) { gate('code'); }
    updateCta(name); updateHint(name);
    if (name==='crossword') { renderCrossword(); }
    if (name==='results')   { renderResults(); }
    // Move focus to the new screen's heading so keyboard + screen-reader users land in place.
    if (userNavigated) { var active = root.querySelector('.stage.is-active'); var h = active && active.querySelector('.stage__title'); if (h && h.focus) { h.focus(); } }
  }
  function show(name){ doShow(name); }

  function ctaLabel(name){
    if (name==='question' || name==='coding') {
      if (!started[name]) { return 'Start'; }
      if (!submitted[name]) { return name==='question' ? 'Confirm answer' : 'Submit code'; }
      return 'Continue';
    }
    if (name==='crossword') { return submitted[name] ? 'Continue' : 'Submit crossword'; }
    if (name==='start')   { return 'Start'; }
    if (name==='review')  { return 'See results'; }
    if (name==='results') { return 'Finish'; }
    return 'Next';
  }
  function updateCta(name){ if ($('cta')) { $('cta').textContent = ctaLabel(name); } }

  if ($('cta')) { $('cta').addEventListener('click', function(){
    userNavigated = true;
    var cur = flow[idx];
    if ((cur==='question' || cur==='coding') && !started[cur]) { startGame(cur); return; }
    if (isGame(cur) && !submitted[cur]) { submitGame(cur); return; }
    idx = Math.min(idx + 1, flow.length - 1); show(flow[idx]);
  }); }

  // ---------- hint (context-aware, once per game) ----------
  function updateHint(name){
    if (!$('hint')) { return; }
    $('hint').disabled = !isGame(name) || !!hintUsed[name] || !!submitted[name] || ((name==='question' || name==='coding') && !started[name]);
  }
  if ($('hint')) { $('hint').addEventListener('click', function(){
    var cur = flow[idx];
    if (!isGame(cur) || hintUsed[cur] || submitted[cur]) { return; }
    hintUsed[cur] = true;
    if (cur==='crossword') { hintCrossword(); }
    if (cur==='question')  { hintQuestion(); }
    if (cur==='coding')    { hintCoding(); }
    updateHint(cur);
  }); }

  // ---------- header + stepper ----------
  if ($('a-sub')) { $('a-sub').textContent = (D.meta && D.meta.subject) || ''; }
  if ($('a-week')) { $('a-week').textContent = (D.meta && D.meta.week) ? ('Week ' + D.meta.week) : ''; }
  if ($('explain-toggle') && $('agon-app')) { $('explain-toggle').addEventListener('change', function(){ $('agon-app').classList.toggle('show-explain', this.checked); }); }
  if ($('stepper')) { $('stepper').innerHTML = steps.map(function(st){ return '<div class="step"><span class="step__dot">'+st.icon+'</span>'+st.label+'</div>'; }).join(''); }

  function feedback(id, ok, html){ var el = $(id); if (!el) { return; } el.className = 'feedback ' + (ok ? 'is-good' : 'is-bad'); el.innerHTML = html; el.hidden = false; }
  function grade(cur){ if (cur==='crossword') { gradeCrossword(); } if (cur==='question') { gradeQuestion(); } if (cur==='coding') { gradeCoding(); } }

  // ---------- CROSSWORD ----------
  function renderCrossword(){
    var host = $('cw'); if (!host || host.dataset.done) { return; }
    var words = (D.crossword && D.crossword.words) || []; var maxR=0, maxC=0, cells={}, nums={};
    cwMap = {};
    words.forEach(function(w){
      for (var i=0;i<w.word.length;i++){
        var r = w.direction==='across' ? w.row : w.row+i;
        var c = w.direction==='across' ? w.col+i : w.col;
        cells[r+'-'+c]=true; cwMap[r+'-'+c] = { correct: w.word.charAt(i).toUpperCase() };
        maxR=Math.max(maxR,r); maxC=Math.max(maxC,c);
      }
      nums[w.row+'-'+w.col]=w.number;
    });
    host.style.gridTemplateColumns = 'repeat(' + (maxC+1) + ', 38px)';
    var html='';
    for (var r=0;r<=maxR;r++) for (var c=0;c<=maxC;c++){
      html += cells[r+'-'+c]
        ? '<div class="cw__cell" data-rc="'+r+'-'+c+'">' + (nums[r+'-'+c]?'<span class="cw__num">'+nums[r+'-'+c]+'</span>':'') + '<input maxlength="1" aria-label="Crossword cell row '+(r+1)+' column '+(c+1)+'"></div>'
        : '<div class="cw__cell" aria-hidden="true"></div>';
    }
    host.innerHTML = html; host.dataset.done = "1";
    host.querySelectorAll('.cw__cell[data-rc]').forEach(function(cell){ var rc = cell.dataset.rc; if (cwMap[rc]) { cwMap[rc].input = cell.querySelector('input'); cwMap[rc].cell = cell; } });
    var li = function(ws){ return ws.map(function(w){ return '<li><b>'+w.number+'.</b> '+esc(w.clue)+'</li>'; }).join(''); };
    if ($('clues')) { $('clues').innerHTML =
      '<h4>Across</h4><ol>' + li(words.filter(function(w){return w.direction==='across';})) + '</ol>' +
      '<h4>Down</h4><ol>' + li(words.filter(function(w){return w.direction==='down';})) + '</ol>'; }
  }
  function hintCrossword(){
    var empties = Object.keys(cwMap).filter(function(k){ return cwMap[k].input && !cwMap[k].input.value; });
    if (!empties.length) { return; }
    var k = empties[Math.floor(Math.random()*empties.length)];
    cwMap[k].input.value = cwMap[k].correct;
    if (cwMap[k].cell) { cwMap[k].cell.classList.add('is-hint'); }
    announce('Hint: revealed the letter ' + cwMap[k].correct + '.');
  }
  function gradeCrossword(){
    var total=0, correct=0;
    Object.keys(cwMap).forEach(function(k){
      var m = cwMap[k]; if (!m.input) { return; }
      total++;
      var val = (m.input.value || '').toUpperCase();
      m.input.disabled = true;
      if (val !== '') { if (val === m.correct) { m.cell.classList.add('is-correct'); correct++; } else { m.cell.classList.add('is-wrong'); } }
    });
    scores.crossword = total ? (correct===total ? 1.0 : (correct>0 ? 0.5 : 0)) : 0;
    feedback('fb-cw', correct===total, (correct===total ? '✓ All correct! ' : '') + correct + ' of ' + total + ' letters correct.');
  }

  // ---------- QUESTION ----------
  function renderQuestion(){
    var host = $('q-host'); if (!host) { return; }
    var q = (D.questions && D.questions[0]) || null;
    if (!q) { host.innerHTML = '<p class="lead">No question configured.</p>'; return; }
    if (host.dataset.done) { return; }
    host.innerHTML = '<div class="q__text">'+esc(q.question)+'</div>' +
      '<p class="peek-hint" style="text-align:left;margin:2px 0 10px">&#128072; reveal an answer (hover, tap, or focus it)</p>' +
      q.options.map(function(o,i){ return '<button class="opt peek" type="button" data-i="'+i+'" aria-pressed="false">'+esc(o)+'</button>'; }).join('');
    host.dataset.done = "1";
    host.querySelectorAll('.opt').forEach(function(o){ o.addEventListener('click', function(){
      if (submitted.question) { return; }
      o.classList.add('is-revealed');
      host.querySelectorAll('.opt').forEach(function(x){ x.classList.remove('is-selected'); x.setAttribute('aria-pressed','false'); });
      o.classList.add('is-selected'); o.setAttribute('aria-pressed','true');
    }); });
  }
  function hintQuestion(){
    var q = D.questions && D.questions[0]; if (!q) { return; }
    if ($('q-hint')) { $('q-hint').innerHTML = '<b>Hint.</b> ' + esc(q.explanation || 'No hint available.'); $('q-hint').hidden = false; }
  }
  function gradeQuestion(){
    var q = D.questions && D.questions[0]; var host = $('q-host'); if (!q || !host) { return; }
    var sel = host.querySelector('.opt.is-selected');
    var selIdx = sel ? parseInt(sel.dataset.i, 10) : -1;
    host.querySelectorAll('.opt').forEach(function(o){
      var i = parseInt(o.dataset.i, 10);
      o.classList.add('is-revealed'); o.disabled = true;
      if (i === q.correct) { o.classList.add('is-correct'); }
      else if (i === selIdx) { o.classList.add('is-wrong'); }
    });
    var ok = selIdx === q.correct;
    scores.question = ok ? 1.0 : 0;
    feedback('fb-q', ok, (ok ? '✓ Correct! +1.0' : '✗ Not quite — the correct answer is highlighted.') +
      (q.explanation ? '<span class="fb-exp">'+esc(q.explanation)+'</span>' : ''));
  }

  // ---------- CODING ----------
  var pick = null, dragging = null;
  function setPick(ch){ if (pick) { pick.classList.remove('is-sel'); pick.setAttribute('aria-pressed','false'); } pick = (pick===ch) ? null : ch; if (pick) { pick.classList.add('is-sel'); pick.setAttribute('aria-pressed','true'); } }
  function fill(blank, chip){ blank.textContent = chip.dataset.v; blank.classList.add('is-filled'); blank.dataset.chip = chip.id; blank.setAttribute('aria-label', 'Blank, filled with ' + chip.dataset.v); chip.classList.add('is-used'); chip.classList.remove('is-sel','is-cued'); chip.setAttribute('aria-pressed','false'); if (pick===chip) { pick = null; } }
  function clearBlank(blank){ var id = blank.dataset.chip, chip = id && $(id); if (chip) { chip.classList.remove('is-used'); } blank.textContent='____'; blank.classList.remove('is-filled'); blank.removeAttribute('data-chip'); blank.setAttribute('aria-label', 'Blank, empty'); }

  function renderCoding(){
    var seqs = (D.coding && D.coding.sequences) || [];
    if ($('review-explain')) { $('review-explain').textContent = seqs.map(function(s){ return s.explanation || ''; }).join(' '); }
    if ($('review-code')) {
      $('review-code').innerHTML = seqs.map(function(s){
        var bi = 0, blanks = s.blanks || s.answers || [];
        return esc(s.code || '').replace(/____/g, function(){ var v = blanks[bi++]; return '<b>'+esc(v != null ? v : '____')+'</b>'; });
      }).join('\n\n');
    }
    var host = $('coding'); if (!host || host.dataset.done) { return; }
    host.innerHTML = seqs.map(function(s,si){
      var bi = 0;
      var lines = (s.code || '').split('\n');
      var linehtml = lines.map(function(line, li){
        var lh = esc(line).replace(/____/g, function(){ var n = bi++; return '<button type="button" class="blank" data-seq="'+si+'" data-b="'+n+'" aria-label="Blank, empty">____</button>'; });
        return '<div class="codeline'+(li === 0 ? '' : ' is-hidden')+'">'+(lh || '&nbsp;')+'</div>';
      }).join('');
      var chips = (s.options || []).map(function(o,oi){ return '<button type="button" class="chip" id="c'+si+'-'+oi+'" draggable="true" data-seq="'+si+'" data-v="'+esc(o)+'" aria-pressed="false" aria-label="Option '+esc(o)+'">'+esc(o)+'</button>'; }).join('');
      var revealbtn = lines.length > 1 ? '<button type="button" class="reveal-line" data-seq="'+si+'" aria-label="Reveal next code line">▼ reveal next line</button>' : '';
      return '<div class="seq"><div class="seq__title">'+esc(s.title || ('Sequence '+(si+1)))+'</div><div class="code">'+linehtml+'</div>'+revealbtn+'<div class="chips" role="group" aria-label="Options for sequence '+(si+1)+'">'+chips+'</div></div>';
    }).join('');
    host.dataset.done = "1";
    host.querySelectorAll('.reveal-line').forEach(function(btn){
      btn.addEventListener('click', function(){
        var seqEl = btn.parentNode;
        seqEl.querySelectorAll('.codeline:not(.is-hidden) .blank').forEach(function(bl){ bl.classList.add('is-locked'); bl.disabled = true; });
        var next = seqEl.querySelector('.codeline.is-hidden');
        if (next) { next.classList.remove('is-hidden'); }
        if (!seqEl.querySelector('.codeline.is-hidden')) { btn.style.display = 'none'; }
        announce('Next line revealed; earlier lines are now locked.');
      });
    });
    host.querySelectorAll('.chip').forEach(function(ch){
      ch.addEventListener('click', function(){ if (!submitted.coding) { setPick(ch); } });
      ch.addEventListener('dragstart', function(){ dragging = ch; });
      ch.addEventListener('dragend', function(){ dragging = null; });
    });
    host.querySelectorAll('.blank').forEach(function(bl){
      bl.addEventListener('click', function(){
        if (submitted.coding || bl.classList.contains('is-locked')) { return; }
        if (bl.classList.contains('is-filled')) { clearBlank(bl); return; }
        if (pick && pick.dataset.seq === bl.dataset.seq) { fill(bl, pick); }
      });
      bl.addEventListener('dragover', function(e){ if (!submitted.coding && !bl.classList.contains('is-locked') && dragging && dragging.dataset.seq===bl.dataset.seq && !dragging.classList.contains('is-used')) { e.preventDefault(); bl.classList.add('drag-over'); } });
      bl.addEventListener('dragleave', function(){ bl.classList.remove('drag-over'); });
      bl.addEventListener('drop', function(e){
        e.preventDefault(); bl.classList.remove('drag-over');
        if (submitted.coding || bl.classList.contains('is-locked')) { return; }
        if (dragging && dragging.dataset.seq===bl.dataset.seq && !dragging.classList.contains('is-used')) {
          if (bl.classList.contains('is-filled')) { clearBlank(bl); }
          fill(bl, dragging);
        }
      });
    });
  }
  function hintCoding(){
    var host = $('coding'); if (!host) { return; }
    var blanks = host.querySelectorAll('.blank');
    for (var i=0;i<blanks.length;i++){
      var bl = blanks[i];
      var line = bl.closest ? bl.closest('.codeline') : null;
      if (line && line.classList.contains('is-hidden')) { continue; }
      if (bl.classList.contains('is-filled') || bl.classList.contains('is-locked')) { continue; }
      var seq = parseInt(bl.dataset.seq,10), b = parseInt(bl.dataset.b,10);
      var want = ((D.coding.sequences[seq] || {}).blanks || [])[b];
      bl.classList.add('is-cued');
      host.querySelectorAll('.chip[data-seq="'+seq+'"]').forEach(function(ch){ if (ch.dataset.v === want && !ch.classList.contains('is-used')) { ch.classList.add('is-cued'); } });
      announce('Hint: the next blank takes ' + want + '.');
      return;
    }
  }
  function gradeCoding(){
    var host = $('coding'); if (!host) { return; }
    var total=0, correct=0;
    host.querySelectorAll('.blank').forEach(function(bl){
      total++; bl.disabled = true;
      var seq = parseInt(bl.dataset.seq,10), b = parseInt(bl.dataset.b,10);
      var want = ((D.coding.sequences[seq] || {}).blanks || [])[b];
      var got = bl.classList.contains('is-filled') ? bl.textContent : '';
      if (got !== '') { if (got === want) { bl.classList.add('is-correct'); correct++; } else { bl.classList.add('is-wrong'); } }
    });
    scores.coding = total ? (correct/total) : 0;
    feedback('fb-code', correct===total, correct + ' of ' + total + ' blanks correct' + (correct===total ? ' — perfect!' : '') + '.');
  }

  // ---------- RESULTS ----------
  function countUp(el, target){
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { el.textContent = target.toFixed(2); return; }
    var t0 = null, dur = 700;
    function step(ts){ if (!t0) { t0 = ts; } var p = Math.min(1, (ts-t0)/dur); el.textContent = (target*p).toFixed(2); if (p<1) { requestAnimationFrame(step); } }
    requestAnimationFrame(step);
  }
  function renderResults(){
    var rows = '', total = 0;
    [['crossword','Crossword'],['question','Question'],['coding','Coding']].forEach(function(d){
      if (games[d[0]]) { var v = scores[d[0]] || 0; total += v; rows += '<div class="score-row"><span>'+d[1]+'</span><b>'+v.toFixed(2)+'</b></div>'; }
    });
    if ($('score-rows')) { $('score-rows').innerHTML = rows; }
    if ($('score-total')) { $('score-total').classList.add('pop'); countUp($('score-total'), total); }
    announce('Your score: ' + total.toFixed(2) + ' points.');
    if ($('lb')) { $('lb').innerHTML = (D.leaderboard || []).slice().sort(function(a,b){return b.pts-a.pts;})
      .map(function(p,i){ return '<li class="'+(p.me?'is-me':'')+'"><span class="rank" aria-hidden="true">'+(i+1)+'</span><span class="who">'+esc(p.name)+'</span><span class="pts">'+p.pts.toFixed(2)+'</span></li>'; }).join(''); }
  }

  show('start');
})();
