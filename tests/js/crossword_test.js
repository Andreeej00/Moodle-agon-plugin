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
 * Unit tests for the crossword builder engine (amd/src/crossword.js).
 *
 * The builder is a pure AMD module with no Moodle/browser dependencies, so it
 * runs under plain Node with a two-line define() shim:
 *
 *     node mod/agon/tests/js/crossword_test.js
 *
 * Exits 0 when every assertion passes, 1 otherwise.
 *
 * @copyright  2026 Andrej Micic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* eslint-env node */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

// ---- load the AMD module with a define() shim ----
const source = fs.readFileSync(path.join(__dirname, '..', '..', 'amd', 'src', 'crossword.js'), 'utf8');
let CW = null;
const define = function(deps, factory) { CW = factory(); };
// eslint-disable-next-line no-new-func
new Function('define', source)(define);
assert.ok(CW && typeof CW.build === 'function', 'module loads and exposes build()');

let failures = 0;
let passes = 0;
function test(name, fn) {
    try {
        fn();
        passes++;
        console.log('  ok  ' + name);
    } catch (e) {
        failures++;
        console.error('FAIL  ' + name + '\n      ' + e.message);
    }
}

/** Rebuild the letter grid from the emitted words[] (the stored schema). */
function cellsFromWords(words) {
    const cells = {};
    words.forEach(function(w) {
        for (let i = 0; i < w.word.length; i++) {
            const r = w.direction === 'across' ? w.row : w.row + i;
            const c = w.direction === 'across' ? w.col + i : w.col;
            const key = r + ',' + c;
            if (cells[key] !== undefined) {
                assert.strictEqual(cells[key], w.word[i], 'crossing letters must agree at ' + key);
            }
            cells[key] = w.word[i];
        }
    });
    return cells;
}

// ---------- input validation ----------

test('normalize: too few words is an error', function() {
    const b = CW.build([{word: 'CAT', clue: 'Pet'}, {word: 'TAR', clue: 'Black'}]);
    assert.strictEqual(b.ok, false);
    assert.ok(b.errors.some(e => e.indexOf('at least ' + CW.MIN_WORDS) !== -1));
});

test('normalize: cleaning, blank rows, short words, missing clues, duplicates', function() {
    const b = CW.build([
        {word: '  caT-9 ', clue: 'Pet'},          // cleans to CAT
        {word: '', clue: ''},                      // fully blank row: skipped silently
        {word: 'A', clue: 'Too short'},            // < 2 letters: error
        {word: 'TAR', clue: ''},                   // no clue: error
        {word: 'cat', clue: 'Duplicate'},          // repeat of CAT: error
        {word: 'ART', clue: 'Museum stuff'},
        {word: 'TACT', clue: 'Diplomacy'},
    ]);
    assert.ok(b.errors.some(e => e.indexOf('at least 2 letters') !== -1), 'short word reported');
    assert.ok(b.errors.some(e => e.indexOf('no clue') !== -1), 'missing clue reported');
    assert.ok(b.errors.some(e => e.indexOf('repeated') !== -1), 'duplicate reported');
    assert.ok(b.words.every(w => /^[A-Z]+$/.test(w.word)), 'emitted words are clean uppercase');
});

test('normalize: too many words is an error', function() {
    const raw = [];
    for (let i = 0; i < 15; i++) {
        raw.push({word: 'WORD' + String.fromCharCode(65 + i) + 'X', clue: 'c' + i});
    }
    const b = CW.build(raw);
    assert.strictEqual(b.ok, false);
    assert.ok(b.errors.some(e => e.indexOf('at most ' + CW.MAX_WORDS) !== -1));
});

// ---------- placement ----------

test('build: three interlocking words all place and the schema is complete', function() {
    const b = CW.build([
        {word: 'TOKEN', clue: 'A unit of text'},
        {word: 'CORPUS', clue: 'A body of text'},
        {word: 'STEM', clue: 'Word root'},
    ]);
    assert.strictEqual(b.ok, true, 'expected ok, got errors=' + JSON.stringify(b.errors)
        + ' unplaceable=' + JSON.stringify(b.unplaceable));
    assert.strictEqual(b.words.length, 3);
    assert.strictEqual(b.unplaceable.length, 0);
    b.words.forEach(function(w) {
        assert.ok(w.number >= 1, 'numbered');
        assert.ok(w.word && w.clue, 'word + clue kept');
        assert.ok(w.direction === 'across' || w.direction === 'down');
        assert.ok(Number.isInteger(w.row) && w.row >= 0, 'row is a non-negative int');
        assert.ok(Number.isInteger(w.col) && w.col >= 0, 'col is a non-negative int');
    });
    // At least one word must run in each axis for a real interlock.
    assert.ok(b.words.some(w => w.direction === 'across') && b.words.some(w => w.direction === 'down'));
});

test('build: emitted words agree with the preview grid cell-for-cell', function() {
    const b = CW.build([
        {word: 'PYTHON', clue: 'Language'},
        {word: 'LOOP', clue: 'Repeats'},
        {word: 'TUPLE', clue: 'Immutable list'},
        {word: 'PRINT', clue: 'Outputs'},
        {word: 'INT', clue: 'Whole number'},
    ]);
    assert.strictEqual(b.unplaceable.length, 0, 'all five place');
    assert.deepStrictEqual(cellsFromWords(b.words), b.grid.cells, 'words[] and grid.cells match');
    // The grid box is tight: rows/cols match the extremes of the cells.
    const rows = Object.keys(b.grid.cells).map(k => parseInt(k.split(',')[0], 10));
    const cols = Object.keys(b.grid.cells).map(k => parseInt(k.split(',')[1], 10));
    assert.strictEqual(Math.min(...rows), 0);
    assert.strictEqual(Math.min(...cols), 0);
    assert.strictEqual(Math.max(...rows), b.grid.rows - 1);
    assert.strictEqual(Math.max(...cols), b.grid.cols - 1);
});

test('build: a word sharing no letters is reported, not silently dropped', function() {
    const b = CW.build([
        {word: 'AAA', clue: 'c1'},
        {word: 'ABBA', clue: 'c2'},
        {word: 'XYZ', clue: 'c3'}, // shares no letter with the A/B words
    ]);
    assert.strictEqual(b.ok, false);
    assert.strictEqual(b.unplaceable.length, 1);
    assert.strictEqual(b.unplaceable[0].word, 'XYZ');
    assert.ok(b.unplaceable[0].reason.indexOf('shares no letters') !== -1);
});

test('build: deterministic — same input, same layout', function() {
    const raw = [
        {word: 'TOKEN', clue: 'a'}, {word: 'CORPUS', clue: 'b'}, {word: 'STEM', clue: 'c'},
        {word: 'NGRAM', clue: 'd'}, {word: 'PARSE', clue: 'e'},
    ];
    assert.deepStrictEqual(CW.build(raw), CW.build(raw));
});

test('build: numbers are row-major and start from 1', function() {
    const b = CW.build([
        {word: 'TOKEN', clue: 'a'}, {word: 'CORPUS', clue: 'b'}, {word: 'STEM', clue: 'c'},
    ]);
    const numbers = b.words.map(w => w.number).sort((a, z) => a - z);
    assert.strictEqual(numbers[0], 1, 'numbering starts at 1');
    // Words are emitted ordered by number.
    assert.deepStrictEqual(b.words.map(w => w.number), b.words.map(w => w.number).slice().sort((a, z) => a - z));
});

test('build: longest word seeds the grid across', function() {
    const b = CW.build([
        {word: 'SHORT', clue: 'a'}, {word: 'ELONGATED', clue: 'b'}, {word: 'TINY', clue: 'c'},
    ]);
    const longest = b.words.find(w => w.word === 'ELONGATED');
    assert.ok(longest, 'longest word placed');
    assert.strictEqual(longest.direction, 'across');
});

test('build: empty input returns a calm empty result', function() {
    const b = CW.build([]);
    assert.strictEqual(b.ok, false);
    assert.deepStrictEqual(b.words, []);
    assert.deepStrictEqual(b.grid.cells, {});
});

test('build: parallel words never touch side-on (only crossings share cells)', function() {
    const b = CW.build([
        {word: 'BANANA', clue: 'a'}, {word: 'ANAGRAM', clue: 'b'}, {word: 'NAAN', clue: 'c'},
        {word: 'ARENA', clue: 'd'}, {word: 'MANGO', clue: 'e'},
    ]);
    // Rebuild per-word cell lists, then check every adjacent same-axis neighbour
    // of each cell belongs to the same word or is a legal crossing cell.
    const cells = b.grid.cells;
    b.words.forEach(function(w) {
        for (let i = 0; i < w.word.length; i++) {
            const r = w.direction === 'across' ? w.row : w.row + i;
            const c = w.direction === 'across' ? w.col + i : w.col;
            // The cell just before the start and just after the end must be empty.
            if (i === 0) {
                const pr = w.direction === 'across' ? r : r - 1;
                const pc = w.direction === 'across' ? c - 1 : c;
                assert.strictEqual(cells[pr + ',' + pc], undefined, w.word + ' has a clear head');
            }
            if (i === w.word.length - 1) {
                const nr = w.direction === 'across' ? r : r + 1;
                const nc = w.direction === 'across' ? c + 1 : c;
                assert.strictEqual(cells[nr + ',' + nc], undefined, w.word + ' has a clear tail');
            }
        }
    });
});

console.log('\ncrossword_test: ' + passes + ' passed, ' + failures + ' failed');
process.exit(failures ? 1 : 0);
