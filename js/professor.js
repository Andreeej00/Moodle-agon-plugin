/* mod_agon professor monitor view (plain JS, frontend skeleton). Reads window.AGON. */
(function(){
  var D = window.AGON; if (!D) return;

  var att = document.getElementById('att-rows');
  if (att) att.innerHTML = D.attempts.map(function(a){
    var total = (a.crossword + a.question + a.coding).toFixed(2);
    return '<tr><td>'+a.name+'</td><td>'+a.crossword.toFixed(2)+'</td><td>'+a.question.toFixed(2)+'</td>' +
           '<td>'+a.coding.toFixed(2)+'</td><td><b>'+total+'</b></td>' +
           '<td>'+(a.done?'<span class="status-yes">&#10003; done</span>':'<span class="status-no">in progress</span>')+'</td></tr>';
  }).join('');

  var lb = document.getElementById('lb-rows');
  if (lb) lb.innerHTML = D.leaderboard.slice().sort(function(a,b){return b.pts-a.pts;})
    .map(function(p,i){ return '<tr><td>'+(i+1)+'</td><td>'+p.name+'</td><td><b>'+p.pts.toFixed(2)+'</b></td></tr>'; }).join('');
})();
