/* Student play-flow: linear stages + bottom stepper. Skeleton only (no scoring). */
(function(){
  const D = window.AGON;
  const flow = ["start","crossword","question","reveal","coding","review","results"];
  const stepOf = { start:0, crossword:0, question:1, reveal:1, coding:2, review:2, results:3 };
  const cta = { start:"Start", crossword:"Submit crossword", question:"Confirm answer",
                reveal:"Next", coding:"Submit code", review:"See results", results:"Finish" };
  let idx = 0;

  document.getElementById('a-sub').textContent = D.meta.subject;
  document.getElementById('a-week').textContent = "Week " + D.meta.week;

  document.getElementById('explain-toggle').addEventListener('change', function(){
    document.body.classList.toggle('show-explain', this.checked);
  });

  function show(name){
    document.querySelectorAll('.stage').forEach(s => s.classList.toggle('is-active', s.id === 'stage-'+name));
    const cur = stepOf[name];
    document.querySelectorAll('.step').forEach((el,i) => { el.classList.toggle('is-active', i===cur); el.classList.toggle('is-done', i<cur); });
    document.getElementById('cta').textContent = cta[name];
    document.getElementById('app-content').scrollTop = 0;
    if (name==='crossword') renderCrossword();
    if (name==='question')  renderQuestion();
    if (name==='coding')    renderCoding();
    if (name==='results')   renderResults();
  }
  document.getElementById('cta').addEventListener('click', () => { idx = Math.min(idx+1, flow.length-1); show(flow[idx]); });
  document.getElementById('hint').addEventListener('click', function(){ this.disabled = true; alert("Hint (one per attempt): the first across word starts with 'T'."); });

  function renderCrossword(){
    const host = document.getElementById('cw'); if (host.dataset.done) return;
    const words = D.crossword.words; let maxR=0, maxC=0; const cells={}, nums={};
    words.forEach(w => {
      for (let i=0;i<w.word.length;i++){
        const r = w.direction==='across' ? w.row : w.row+i;
        const c = w.direction==='across' ? w.col+i : w.col;
        cells[r+'-'+c]=true; maxR=Math.max(maxR,r); maxC=Math.max(maxC,c);
      }
      nums[w.row+'-'+w.col]=w.number;
    });
    host.style.gridTemplateColumns = `repeat(${maxC+1}, 38px)`;
    let html='';
    for (let r=0;r<=maxR;r++) for (let c=0;c<=maxC;c++){
      html += cells[r+'-'+c]
        ? `<div class="cw__cell">${nums[r+'-'+c]?`<span class="cw__num">${nums[r+'-'+c]}</span>`:''}<input maxlength="1"></div>`
        : `<div class="cw__cell"></div>`;
    }
    host.innerHTML = html; host.dataset.done = "1";
    const li = ws => ws.map(w => `<li><b>${w.number}.</b> ${w.clue}</li>`).join('');
    document.getElementById('clues').innerHTML =
      `<h4>Across</h4><ol>${li(words.filter(w=>w.direction==='across'))}</ol>` +
      `<h4>Down</h4><ol>${li(words.filter(w=>w.direction==='down'))}</ol>`;
  }

  function renderQuestion(){
    const host = document.getElementById('q-host');
    const q = D.questions[0];
    document.getElementById('reveal-answer').textContent = q.options[q.correct] + " — correct answer.";
    document.getElementById('q-explain').textContent = q.explanation;
    if (host.dataset.done) return;
    host.innerHTML = `<div class="q__text">${q.question}</div>` + q.options.map(o => `<button class="opt">${o}</button>`).join('');
    host.dataset.done = "1";
    host.querySelectorAll('.opt').forEach(o => o.addEventListener('click', () => {
      host.querySelectorAll('.opt').forEach(x => x.classList.remove('is-selected'));
      o.classList.add('is-selected');
    }));
  }

  // coding: click-to-place + drag-and-drop + deselect/clear
  let pick = null, dragging = null;
  function setPick(ch){ if (pick) pick.classList.remove('is-sel'); pick = (pick===ch) ? null : ch; if (pick) pick.classList.add('is-sel'); }
  function fill(blank, chip){ blank.textContent = chip.dataset.v; blank.classList.add('is-filled'); blank.dataset.chip = chip.id; chip.classList.add('is-used'); chip.classList.remove('is-sel'); if (pick===chip) pick = null; }
  function clearBlank(blank){ const id = blank.dataset.chip; const chip = id && document.getElementById(id); if (chip) chip.classList.remove('is-used'); blank.textContent='____'; blank.classList.remove('is-filled'); blank.removeAttribute('data-chip'); }

  function renderCoding(){
    const host = document.getElementById('coding'); if (host.dataset.done) return;
    document.getElementById('review-explain').textContent = D.coding.sequences.map(s=>s.explanation).join(' ');
    host.innerHTML = D.coding.sequences.map((s,si) => {
      const code = s.lines.map(l => l.replace(/\[\[(\d+)\]\]/g,(m,b)=>`<span class="blank" data-seq="${si}" data-b="${b}">____</span>`)).join('\n');
      const chips = s.options.map((o,oi) => `<span class="chip" id="c${si}-${oi}" draggable="true" data-seq="${si}" data-v="${o}">${o}</span>`).join('');
      return `<div class="seq"><div class="seq__title">${s.title}</div><div class="code">${code}</div><div class="chips">${chips}</div></div>`;
    }).join('');
    host.dataset.done = "1";

    host.querySelectorAll('.chip').forEach(ch => {
      ch.addEventListener('click', () => setPick(ch));
      ch.addEventListener('dragstart', () => { dragging = ch; });
      ch.addEventListener('dragend', () => { dragging = null; });
    });
    host.querySelectorAll('.blank').forEach(bl => {
      bl.addEventListener('click', () => {
        if (bl.classList.contains('is-filled')) { clearBlank(bl); return; }          // click filled blank → deselect/clear
        if (pick && pick.dataset.seq === bl.dataset.seq) fill(bl, pick);
      });
      bl.addEventListener('dragover', e => { if (dragging && dragging.dataset.seq===bl.dataset.seq && !dragging.classList.contains('is-used')) { e.preventDefault(); bl.classList.add('drag-over'); } });
      bl.addEventListener('dragleave', () => bl.classList.remove('drag-over'));
      bl.addEventListener('drop', e => {
        e.preventDefault(); bl.classList.remove('drag-over');
        if (dragging && dragging.dataset.seq===bl.dataset.seq && !dragging.classList.contains('is-used')) {
          if (bl.classList.contains('is-filled')) clearBlank(bl);
          fill(bl, dragging);
        }
      });
    });
  }

  function renderResults(){
    document.getElementById('lb').innerHTML = D.leaderboard.slice().sort((a,b)=>b.pts-a.pts)
      .map((p,i) => `<li class="${p.me?'is-me':''}"><span class="rank">${i+1}</span><span class="who">${p.name}</span><span class="pts">${p.pts.toFixed(2)}</span></li>`).join('');
  }

  show('start');
})();
