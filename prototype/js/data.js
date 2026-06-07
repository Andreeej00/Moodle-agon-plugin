/* Sample content for the Agon UI skeleton (mock data only, English).
   In the real plugin, the professor pastes JSON like this per game at setup. */
window.AGON = {
  meta: { subject:"IT 2012 – Unstructured Data", code:"IT2012", week:5, topic:"Tokenization", description:"Core NLP concepts", language:"en" },

  enabledGames: { crossword:true, question:true, coding:true },

  // Crossword content. (Grid cells render empty; the config tool validates intersections.)
  crossword: {
    id:"crossword-it2012-w05-01", difficulty:"medium", author:"Name Surname",
    words: [
      { number:1, word:"TOKEN",  clue:"Smallest unit of text after splitting it into pieces", direction:"across", row:0, col:0 },
      { number:2, word:"CORPUS", clue:"A structured collection of text documents for analysis", direction:"down",  row:0, col:2 },
      { number:3, word:"LEMMA",  clue:"Base form of a word after normalization (e.g. 'run' for 'running')", direction:"across", row:3, col:1 }
    ]
  },

  // Weekly questions (~5). `correct` = index into options.
  questions: [
    { question:"Which process splits text into its smallest units (tokens)?", options:["Tokenization","Lemmatization","Normalization","Vectorization"], correct:0, explanation:"Tokenization breaks text into tokens — the smallest units used for analysis." },
    { question:"What is a 'corpus' in text processing?", options:["A collection of text documents","A search algorithm","A type of database","A programming language"], correct:0, explanation:"A corpus is a structured collection of documents used as data for analysis." },
    { question:"Lemmatization reduces a word to its...?", options:["Base form","Letter root","ASCII code","Hash"], correct:0, explanation:"Lemmatization maps a word to its dictionary base form, the lemma." },
    { question:"Removing frequent words (the, a, of) is called removing...?", options:["Stop words","Tokens","Lemmas","Corpora"], correct:0, explanation:"High-frequency, low-information words are called stop words." },
    { question:"Turning text into numbers (vectors) is...?", options:["Vectorization","Tokenization","Lemmatization","Segmentation"], correct:0, explanation:"Vectorization converts text into numeric vectors that models can use." }
  ],

  // Coding game — two sequences, each 0.5, partial per correct placement. [[n]] marks a blank.
  coding: {
    sequences: [
      { title:"Sequence 1 — tokenization",
        lines:['text = "Hello World"','tokens = text.[[0]]()','tokens = [t.[[1]]() for t in tokens]','print([[2]](tokens))'],
        options:["split","lower","len","upper","join","strip"], answers:["split","lower","len"],
        explanation:"split() breaks the string into tokens, lower() normalizes case, len() counts them." },
      { title:"Sequence 2 — filtering",
        lines:['words = ["the","cat","sat","a"]','res = [[0]](filter(lambda w: len(w) > 2, words))','res = [w.[[1]]() for w in res]','print(res[[[2]]])'],
        options:["list","upper","0","set","title","1"], answers:["list","upper","0"],
        explanation:"list() materializes the filter, upper() transforms each word, res[0] takes the first." }
    ]
  },

  // Course-wide cumulative leaderboard.
  leaderboard: [
    { name:"Amila H.", pts:14.25 }, { name:"Tarik B.", pts:13.50 }, { name:"Lejla K.", pts:12.75 },
    { name:"You", pts:11.50, me:true }, { name:"Emir S.", pts:10.25 }, { name:"Nina P.", pts:9.00 }
  ],

  // This activity's attempts (professor monitoring; professors do not play).
  attempts: [
    { name:"Amila H.", crossword:1.00, question:1.00, coding:0.50, done:true },
    { name:"Tarik B.", crossword:0.75, question:1.00, coding:0.40, done:true },
    { name:"Lejla K.", crossword:0.75, question:0.00, coding:0.50, done:true },
    { name:"Emir S.",  crossword:0.50, question:1.00, coding:0.30, done:true },
    { name:"Nina P.",  crossword:0.50, question:0.00, coding:0.00, done:false }
  ]
};
