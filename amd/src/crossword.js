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
 * Crossword builder engine for mod_agon (teacher-side authoring).
 *
 * Pure layout algorithm: given 3–14 {word, clue} pairs it finds shared letters
 * and interlocks the words into one grid, longest first. Words that share no
 * letter with the rest (or can't fit without clashing) are reported so the
 * teacher can fix them. The emitted words[] use plain integer row/col in the
 * stored crossword schema (number/word/clue/direction/row/col), so the student
 * player and the server scoring consume them with no changes.
 *
 * Internal cell keys use a comma ("row,col") because coordinates go negative
 * during placement (a word can cross above/left of the seed) and a "-" join
 * would be ambiguous. result.grid.cells uses the same "row,col" keys.
 *
 * @module     mod_agon/crossword
 * @copyright  2026 Andrej Micic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    var MIN_WORDS = 3;
    var MAX_WORDS = 14;

    /**
     * Uppercase a word and strip anything that is not a letter.
     *
     * @param {String} word
     * @return {String}
     */
    function clean(word) {
        return String(word === null || word === undefined ? '' : word).toUpperCase().replace(/[^A-Z]/g, '');
    }

    /**
     * Validate + clean the raw {word, clue} rows.
     *
     * @param {Array} raw
     * @return {{items: Array, errors: Array}}
     */
    function normalize(raw) {
        var items = [], errors = [], seen = {};
        (raw || []).forEach(function(row) {
            var w = clean(row && row.word);
            var clue = String((row && row.clue) || '').trim();
            if (!w && !clue) { return; } // A fully blank row is just skipped.
            if (w.length < 2) { errors.push('"' + ((row && row.word) || '') + '" needs at least 2 letters.'); return; }
            if (!clue) { errors.push('"' + w + '" has no clue.'); return; }
            if (seen[w]) { errors.push('"' + w + '" is repeated.'); return; }
            seen[w] = true;
            items.push({word: w, clue: clue});
        });
        if (items.length < MIN_WORDS) { errors.push('Add at least ' + MIN_WORDS + ' words (you have ' + items.length + ').'); }
        if (items.length > MAX_WORDS) { errors.push('Use at most ' + MAX_WORDS + ' words (you have ' + items.length + ').'); }
        return {items: items, errors: errors};
    }

    /**
     * Write a word into the grid map ("row,col" => letter).
     *
     * @param {Object} grid
     * @param {String} word
     * @param {Number} row
     * @param {Number} col
     * @param {String} dir across|down
     */
    function putWord(grid, word, row, col, dir) {
        var dr = dir === 'down' ? 1 : 0, dc = dir === 'across' ? 1 : 0;
        for (var k = 0; k < word.length; k++) { grid[(row + dr * k) + ',' + (col + dc * k)] = word.charAt(k); }
    }

    /**
     * Does the word share any letter with what is already on the grid?
     *
     * @param {String} word
     * @param {Object} grid
     * @return {Boolean}
     */
    function sharesLetter(word, grid) {
        var letters = {};
        Object.keys(grid).forEach(function(k) { letters[grid[k]] = true; });
        for (var i = 0; i < word.length; i++) { if (letters[word.charAt(i)]) { return true; } }
        return false;
    }

    /**
     * Count intersections for a concrete placement, or null if it is illegal.
     *
     * Illegal = a letter clash, running straight into another word at either end,
     * a new cell touching another word side-on (a parallel run), or no crossing.
     *
     * @param {String} word
     * @param {Number} row
     * @param {Number} col
     * @param {String} dir across|down
     * @param {Object} grid
     * @return {Number|null} Number of intersections, or null when invalid.
     */
    function tryPlace(word, row, col, dir, grid) {
        var dr = dir === 'down' ? 1 : 0, dc = dir === 'across' ? 1 : 0;
        // The cells just before the start and just after the end must be empty.
        if (grid[(row - dr) + ',' + (col - dc)] !== undefined) { return null; }
        if (grid[(row + dr * word.length) + ',' + (col + dc * word.length)] !== undefined) { return null; }
        var intersections = 0;
        for (var k = 0; k < word.length; k++) {
            var r = row + dr * k, c = col + dc * k, here = grid[r + ',' + c];
            if (here !== undefined) {
                if (here !== word.charAt(k)) { return null; } // Letter clash.
                intersections++;
            } else {
                // A brand-new cell may not sit alongside another word (only crossings may touch).
                var pr = dir === 'across' ? 1 : 0, pc = dir === 'across' ? 0 : 1;
                if (grid[(r - pr) + ',' + (c - pc)] !== undefined) { return null; }
                if (grid[(r + pr) + ',' + (c + pc)] !== undefined) { return null; }
            }
        }
        return intersections === 0 ? null : intersections;
    }

    /**
     * Best placement for a word against the current grid, or null if none fits.
     * Prefers the most crossings, then the most compact (closest to the origin).
     *
     * @param {String} word
     * @param {Object} grid
     * @return {Object|null} {row, col, direction} or null.
     */
    function findPlacement(word, grid) {
        var best = null, keys = Object.keys(grid);
        for (var i = 0; i < word.length; i++) {
            var ch = word.charAt(i);
            for (var g = 0; g < keys.length; g++) {
                if (grid[keys[g]] !== ch) { continue; }
                var p = keys[g].split(','), gr = parseInt(p[0], 10), gc = parseInt(p[1], 10);
                var dirs = ['across', 'down'];
                for (var d = 0; d < dirs.length; d++) {
                    var dir = dirs[d];
                    var row = dir === 'across' ? gr : gr - i;
                    var col = dir === 'across' ? gc - i : gc;
                    var inter = tryPlace(word, row, col, dir, grid);
                    if (inter === null) { continue; }
                    var tie = Math.abs(row) + Math.abs(col);
                    if (best === null || inter > best.score ||
                        (inter === best.score && tie < best.tie) ||
                        (inter === best.score && tie === best.tie && row < best.row) ||
                        (inter === best.score && tie === best.tie && row === best.row && col < best.col)) {
                        best = {row: row, col: col, direction: dir, score: inter, tie: tie};
                    }
                }
            }
        }
        return best;
    }

    /**
     * Number the across/down start cells in row-major order.
     *
     * @param {Object} cells "row,col" => letter.
     * @return {Object} "row,col" => number for the cells that start a word.
     */
    function assignNumbers(cells) {
        var list = Object.keys(cells).map(function(k) { var p = k.split(','); return {r: parseInt(p[0], 10), c: parseInt(p[1], 10)}; });
        list.sort(function(a, b) { return a.r - b.r || a.c - b.c; });
        var numbers = {}, n = 0;
        list.forEach(function(cell) {
            var r = cell.r, c = cell.c;
            var startsAcross = cells[r + ',' + (c - 1)] === undefined && cells[r + ',' + (c + 1)] !== undefined;
            var startsDown = cells[(r - 1) + ',' + c] === undefined && cells[(r + 1) + ',' + c] !== undefined;
            if (startsAcross || startsDown) { n++; numbers[r + ',' + c] = n; }
        });
        return numbers;
    }

    /**
     * Build a crossword from raw {word, clue} rows.
     *
     * @param {Array} raw List of {word, clue}.
     * @return {Object} {ok, words, unplaceable, errors, grid:{rows, cols, cells}}
     */
    function build(raw) {
        var norm = normalize(raw);
        var result = {ok: false, words: [], unplaceable: [], errors: norm.errors, grid: {rows: 0, cols: 0, cells: {}}};
        if (norm.items.length === 0) { return result; }

        // Longest word first interlocks best; ties keep the teacher's order for stability.
        var order = norm.items.map(function(it, i) { return {it: it, i: i}; })
            .sort(function(a, b) { return b.it.word.length - a.it.word.length || a.i - b.i; });

        var grid = {}, placed = [];
        var items = order.map(function(entry) { return entry.it; });
        // Seed the longest word across the origin.
        putWord(grid, items[0].word, 0, 0, 'across');
        placed.push({word: items[0].word, clue: items[0].clue, row: 0, col: 0, direction: 'across'});
        // Multiple passes: a word that doesn't fit yet may fit once later words add
        // crossing letters, so keep retrying the leftovers until no more can land.
        var pending = items.slice(1), progress = true;
        while (pending.length && progress) {
            progress = false;
            var still = [];
            pending.forEach(function(it) {
                var p = findPlacement(it.word, grid);
                if (p) {
                    putWord(grid, it.word, p.row, p.col, p.direction);
                    placed.push({word: it.word, clue: it.clue, row: p.row, col: p.col, direction: p.direction});
                    progress = true;
                } else {
                    still.push(it);
                }
            });
            pending = still;
        }
        pending.forEach(function(it) {
            result.unplaceable.push({
                word: it.word, clue: it.clue,
                reason: sharesLetter(it.word, grid)
                    ? 'could not be placed without clashing with another word'
                    : 'shares no letters with the other words'
            });
        });

        // Shift everything so the grid starts at (0,0).
        var minR = Infinity, minC = Infinity, maxR = -Infinity, maxC = -Infinity;
        Object.keys(grid).forEach(function(k) {
            var p = k.split(','), r = parseInt(p[0], 10), c = parseInt(p[1], 10);
            if (r < minR) { minR = r; }
            if (c < minC) { minC = c; }
            if (r > maxR) { maxR = r; }
            if (c > maxC) { maxC = c; }
        });
        if (!isFinite(minR)) { minR = 0; minC = 0; maxR = -1; maxC = -1; }
        placed.forEach(function(pw) { pw.row -= minR; pw.col -= minC; });
        var cells = {};
        Object.keys(grid).forEach(function(k) {
            var p = k.split(',');
            cells[(parseInt(p[0], 10) - minR) + ',' + (parseInt(p[1], 10) - minC)] = grid[k];
        });

        // Number the words, then emit them in the stored schema, ordered by number.
        var numbers = assignNumbers(cells);
        placed.forEach(function(pw) { pw.number = numbers[pw.row + ',' + pw.col] || 0; });
        placed.sort(function(a, b) { return a.number - b.number; });
        result.words = placed.map(function(pw) {
            return {number: pw.number, word: pw.word, clue: pw.clue, direction: pw.direction, row: pw.row, col: pw.col};
        });
        result.grid = {rows: (maxR - minR + 1) || 0, cols: (maxC - minC + 1) || 0, cells: cells};
        result.ok = result.errors.length === 0 && result.unplaceable.length === 0 && result.words.length >= MIN_WORDS;
        return result;
    }

    return {
        build: build,
        MIN_WORDS: MIN_WORDS,
        MAX_WORDS: MAX_WORDS
    };
});
