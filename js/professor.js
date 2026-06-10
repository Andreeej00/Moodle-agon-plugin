/* mod_agon professor monitor (plain JS). Reads window.AGON (real attempt data).
   The attempts table is searchable by student name and filterable by state. */
(function(){
  var D = window.AGON; if (!D) return;

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  // ---------- attempts: search + state filter ----------
  var rows = D.attempts || [];
  var att = document.getElementById('att-rows');
  var search = document.getElementById('att-search');
  var state = document.getElementById('att-state');

  function rowHtml(a){
    var total = (a.crossword + a.question + a.coding).toFixed(2);
    return '<tr><td>'+esc(a.name)+'</td><td>'+a.crossword.toFixed(2)+'</td><td>'+a.question.toFixed(2)+'</td>' +
           '<td>'+a.coding.toFixed(2)+'</td><td><b>'+total+'</b></td>' +
           '<td>'+(a.done?'<span class="status-yes">&#10003; done</span>':'<span class="status-no">in progress</span>')+'</td></tr>';
  }
  function render(){
    if (!att) return;
    var q = search && search.value ? search.value.trim().toLowerCase() : '';
    var st = state ? state.value : 'all';
    var list = rows.filter(function(a){
      if (q && String(a.name).toLowerCase().indexOf(q) === -1) { return false; }
      if (st === 'finished' && !a.done) { return false; }
      if (st === 'inprogress' && a.done) { return false; }
      return true;
    });
    att.innerHTML = list.map(rowHtml).join('') ||
      '<tr><td colspan="6" class="lbt-empty">' + (rows.length ? 'No attempts match your search.' : 'No attempts yet.') + '</td></tr>';
  }
  if (search) search.addEventListener('input', render);
  if (state) state.addEventListener('change', render);
  render();

  // ---------- course leaderboard ----------
  var lb = document.getElementById('lb-rows');
  if (lb) lb.innerHTML = ((D.leaderboard || []).slice().sort(function(a,b){return b.pts-a.pts;})
    .map(function(p,i){ return '<tr><td>'+(i+1)+'</td><td>'+esc(p.name)+'</td><td><b>'+p.pts.toFixed(2)+'</b></td></tr>'; }).join('')) ||
    '<tr><td colspan="3" class="lbt-empty">No scores yet.</td></tr>';
})();
