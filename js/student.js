/* mod_agon student play-flow (plain JS). Reads window.AGON (saved config, or example fallback).
   Defensive: attaches the primary action handler first and guards every DOM lookup. */
(function(){
  var D = window.AGON;
  var dataEl = document.getElementById('agon-data');
  if (!D && dataEl) { try { D = JSON.parse(dataEl.textContent); } catch (e) {} }
  if (!D) { if (window.console) { console.error('Agon: no data (window.AGON missing)'); } return; }

  function $(id){ return document.getElementById(id); }
  var root = $('agon-app') || document;
  var games = D.enabledGames || { crossword:true, question:true, coding:true };
  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  // Build the flow + stepper from the games the teacher enabled.
  var steps = [], flow = ['start'];
  if (games.crossword) { steps.push({label:'Crossword', icon:'▦', stages:['crossword']}); flow.push('crossword'); }
  if (games.question)  { steps.push({label:'Question', icon:'?', stages:['question','reveal']}); flow.push('question','reveal'); }
  if (games.coding)    { steps.push({label:'Code', icon:'{ }', stages:['coding','review']}); flow.push('coding','review'); }
  steps.push({label:'Result', icon:'★', stages:['results']}); flow.push('results');
  var stepOf = { start:0 };
  steps.forEach(function(st, i){ st.stages.forEach(function(sg){ stepOf[sg] = i; }); });

  var ctaLabels = { start:'Start', crossword:'Submit crossword', question:'Confirm answer', reveal:'Next', coding:'Submit code', review:'See results', results:'Finish' };
  var idx = 0;

  function show(name){
    root.querySelectorAll('.stage').forEach(function(s){ s.classList.toggle('is-active', s.id === 'stage-'+name); });
    var cur = stepOf[name];
    root.querySelectorAll('.step').forEach(function(el,i){ el.classList.toggle('is-active', i===cur); el.classList.toggle('is-done', i<cur); });
    if ($('cta')) { $('cta').textContent = ctaLabels[name] || 'Next'; }
    if ($('app-content')) { $('app-content').scrollTop = 0; }
    if (name==='crossword') { renderCrossword(); }
    if (name==='question')  { renderQuestion(); }
    if (name==='coding')    { renderCoding(); }
    if (name==='results')   { renderResults(); }
  }

  // --- Attach handlers FIRST so the run always advances, even if later rendering hiccups. ---
  if ($('cta')) { $('cta').addEventListener('click', function(){ idx = Math.min(idx + 1, flow.length - 1); show(flow[idx]); }); }
  if ($('hint')) { $('hint').addEventListener('click', function(){ this.disabled = true; alert('Hint: one per attempt.'); }); }
  if ($('explain-toggle') && $('agon-app')) {
    $('explain-toggle').addEventListener('change', function(){ $('agon-app').classList.toggle('show-explain', this.checked); });
  }

  // --- Header + stepper (guarded; cosmetic). ---
  if ($('a-sub')) { $('a-sub').textContent = (D.meta && D.meta.subject) || ''; }
  if ($('a-week')) { $('a-week').textContent = (D.meta && D.meta.week) ? ('Week ' + D.meta.week) : ''; }
  if ($('stepper')) {
    $('stepper').innerHTML = steps.map(function(st){ return '<div class="step"><span class="step__dot">'+st.icon+'</span>'+st.label+'</div>'; }).join('');
  }

  function renderCrossword(){
    var host = $('cw'); if (!host || host.dataset.done) { return; }
    var words = (D.crossword && D.crossword.words) || []; var maxR=0, maxC=0, cells={}, nums={};
    words.forEach(function(w){
      for (var i=0;i<w.word.length;i++){
        var r = w.direction==='across' ? w.row : w.row+i;
        var c = w.direction==='across' ? w.col+i : w.col;
        cells[r+'-'+c]=true; maxR=Math.max(maxR,r); maxC=Math.max(maxC,c);
      }
      nums[w.row+'-'+w.col]=w.number;
    });
    host.style.gridTemplateColumns = 'repeat(' + (maxC+1) + ', 38px)';
    var html='';
    for (var r=0;r<=maxR;r++) for (var c=0;c<=maxC;c++){
      html += cells[r+'-'+c] ? '<div class="cw__cell">' + (nums[r+'-'+c]?'<span class="cw__num">'+nums[r+'-'+c]+'</span>':'') + '<input maxlength="1"></div>' : '<div class="cw__cell"></div>';
    }
    host.innerHTML = html; host.dataset.done = "1";
    var li = function(ws){ return ws.map(function(w){ return '<li><b>'+w.number+'.</b> '+esc(w.clue)+'</li>'; }).join(''); };
    if ($('clues')) {
      $('clues').innerHTML =
        '<h4>Across</h4><ol>' + li(words.filter(function(w){return w.direction==='across';})) + '</ol>' +
        '<h4>Down</h4><ol>' + li(words.filter(function(w){return w.direction==='down';})) + '</ol>';
    }
  }

  function renderQuestion(){
    var host = $('q-host'); if (!host) { return; }
    var q = (D.questions && D.questions[0]) || null;
    if (!q) { host.innerHTML = '<p class="lead">No question configured.</p>'; return; }
    if ($('reveal-answer')) { $('reveal-answer').textContent = q.options[q.correct] + ' — correct answer.'; }
    if ($('q-explain')) { $('q-explain').textContent = q.explanation || ''; }
    if (host.dataset.done) { return; }
    host.innerHTML = '<div class="q__text">'+esc(q.question)+'</div>' + q.options.map(function(o){ return '<button class="opt" type="button">'+esc(o)+'</button>'; }).join('');
    host.dataset.done = "1";
    host.querySelectorAll('.opt').forEach(function(o){ o.addEventListener('click', function(){
      host.querySelectorAll('.opt').forEach(function(x){ x.classList.remove('is-selected'); });
      o.classList.add('is-selected');
    }); });
  }

  var pick = null, dragging = null;
  function setPick(ch){ if (pick) pick.classList.remove('is-sel'); pick = (pick===ch) ? null : ch; if (pick) pick.classList.add('is-sel'); }
  function fill(blank, chip){ blank.textContent = chip.dataset.v; blank.classList.add('is-filled'); blank.dataset.chip = chip.id; chip.classList.add('is-used'); chip.classList.remove('is-sel'); if (pick===chip) pick = null; }
  function clearBlank(blank){ var id = blank.dataset.chip, chip = id && $(id); if (chip) chip.classList.remove('is-used'); blank.textContent='____'; blank.classList.remove('is-filled'); blank.removeAttribute('data-chip'); }

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
      var codehtml = esc(s.code || '').replace(/____/g, function(){ return '<span class="blank" data-seq="'+si+'" data-b="'+(bi++)+'">____</span>'; });
      var chips = (s.options || []).map(function(o,oi){ return '<span class="chip" id="c'+si+'-'+oi+'" draggable="true" data-seq="'+si+'" data-v="'+esc(o)+'">'+esc(o)+'</span>'; }).join('');
      return '<div class="seq"><div class="seq__title">'+esc(s.title || ('Sequence '+(si+1)))+'</div><div class="code">'+codehtml+'</div><div class="chips">'+chips+'</div></div>';
    }).join('');
    host.dataset.done = "1";
    host.querySelectorAll('.chip').forEach(function(ch){
      ch.addEventListener('click', function(){ setPick(ch); });
      ch.addEventListener('dragstart', function(){ dragging = ch; });
      ch.addEventListener('dragend', function(){ dragging = null; });
    });
    host.querySelectorAll('.blank').forEach(function(bl){
      bl.addEventListener('click', function(){
        if (bl.classList.contains('is-filled')) { clearBlank(bl); return; }
        if (pick && pick.dataset.seq === bl.dataset.seq) { fill(bl, pick); }
      });
      bl.addEventListener('dragover', function(e){ if (dragging && dragging.dataset.seq===bl.dataset.seq && !dragging.classList.contains('is-used')) { e.preventDefault(); bl.classList.add('drag-over'); } });
      bl.addEventListener('dragleave', function(){ bl.classList.remove('drag-over'); });
      bl.addEventListener('drop', function(e){
        e.preventDefault(); bl.classList.remove('drag-over');
        if (dragging && dragging.dataset.seq===bl.dataset.seq && !dragging.classList.contains('is-used')) {
          if (bl.classList.contains('is-filled')) { clearBlank(bl); }
          fill(bl, dragging);
        }
      });
    });
  }

  function renderResults(){
    if (!$('lb')) { return; }
    $('lb').innerHTML = (D.leaderboard || []).slice().sort(function(a,b){return b.pts-a.pts;})
      .map(function(p,i){ return '<li class="'+(p.me?'is-me':'')+'"><span class="rank">'+(i+1)+'</span><span class="who">'+esc(p.name)+'</span><span class="pts">'+p.pts.toFixed(2)+'</span></li>'; }).join('');
  }

  show('start');
})();
