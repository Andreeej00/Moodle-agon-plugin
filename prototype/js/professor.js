/* Professor view: configure (JSON per game) + monitor attempts + leaderboard. No play. */
(function(){
  const D = window.AGON;

  document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.remove('is-active'));
    document.querySelectorAll('.panel').forEach(x => x.classList.remove('is-active'));
    t.classList.add('is-active');
    document.getElementById('p-'+t.dataset.p).classList.add('is-active');
  }));

  // JSON content per game (what the professor pastes at setup)
  const cwJson = {
    subject: D.meta.subject, subject_code: D.meta.code, week: D.meta.week,
    id: D.crossword.id, topic: D.meta.topic, description: D.meta.description,
    language: D.meta.language, difficulty: D.crossword.difficulty, author: D.crossword.author,
    words: D.crossword.words
  };
  const qJson = {
    subject_code: D.meta.code, week: D.meta.week, topic: D.meta.topic,
    questions: D.questions.map(q => ({ question:q.question, options:q.options, correct:q.correct, explanation:q.explanation }))
  };
  const codeJson = {
    subject_code: D.meta.code, week: D.meta.week,
    sequences: D.coding.sequences.map(s => ({
      title: s.title, code: s.lines.join('\n').replace(/\[\[\d+\]\]/g,'____'),
      blanks: s.answers, options: s.options
    }))
  };
  document.getElementById('cw-json').value   = JSON.stringify(cwJson, null, 2);
  document.getElementById('q-json').value    = JSON.stringify(qJson, null, 2);
  document.getElementById('code-json').value = JSON.stringify(codeJson, null, 2);

  // crossword preview (read-only mini grid)
  (function(){
    const words = D.crossword.words; let maxR=0, maxC=0; const cells={}, nums={};
    words.forEach(w => { for(let i=0;i<w.word.length;i++){
      const r = w.direction==='across'? w.row : w.row+i;
      const c = w.direction==='across'? w.col+i : w.col;
      cells[r+'-'+c]=true; maxR=Math.max(maxR,r); maxC=Math.max(maxC,c);
    } nums[w.row+'-'+w.col]=w.number; });
    const host = document.getElementById('cw-prev');
    host.style.gridTemplateColumns = `repeat(${maxC+1}, 28px)`;
    let html='';
    for(let r=0;r<=maxR;r++) for(let c=0;c<=maxC;c++){
      html += cells[r+'-'+c] ? `<div class="cw__cell">${nums[r+'-'+c]?`<span class="cw__num">${nums[r+'-'+c]}</span>`:''}<input maxlength="1"></div>` : `<div class="cw__cell"></div>`;
    }
    host.innerHTML = html;
  })();

  // attempts (monitoring)
  document.getElementById('att-rows').innerHTML = D.attempts.map(a => {
    const total = (a.crossword + a.question + a.coding).toFixed(2);
    return `<tr><td>${a.name}</td><td>${a.crossword.toFixed(2)}</td><td>${a.question.toFixed(2)}</td>
      <td>${a.coding.toFixed(2)}</td><td><b>${total}</b></td>
      <td>${a.done?'<span class="status-yes">✓ done</span>':'<span class="status-no">in progress</span>'}</td></tr>`;
  }).join('');

  // leaderboard (course-wide)
  document.getElementById('lb-rows').innerHTML = D.leaderboard.slice().sort((a,b)=>b.pts-a.pts)
    .map((p,i) => `<tr><td>${i+1}</td><td>${p.name}</td><td><b>${p.pts.toFixed(2)}</b></td></tr>`).join('');
})();
