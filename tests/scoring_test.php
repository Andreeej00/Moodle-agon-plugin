<?php
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

namespace mod_agon;

use mod_agon\local\scoring;

/**
 * Unit tests for the server-side scoring engine.
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\local\scoring
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scoring_test extends \advanced_testcase {
    /**
     * Weekly question: 1.0 for the correct option, 0.0 otherwise.
     */
    public function test_score_question(): void {
        $q = ['correct' => 2];
        $this->assertEqualsWithDelta(1.0, scoring::score_question($q, 2), 1e-9);
        $this->assertEqualsWithDelta(0.0, scoring::score_question($q, 0), 1e-9);
        $this->assertEqualsWithDelta(0.0, scoring::score_question($q, null), 1e-9);
    }

    /**
     * Coding: two sequences share 1.0 evenly, partial per correct blank.
     */
    public function test_score_coding(): void {
        $seqs = [['blanks' => ['split', 'lower']], ['blanks' => ['list', '0']]];
        $this->assertEqualsWithDelta(1.0, scoring::score_coding($seqs, [['split', 'lower'], ['list', '0']]), 1e-9);
        // One sequence perfect, the other wrong → 0.5.
        $this->assertEqualsWithDelta(0.5, scoring::score_coding($seqs, [['split', 'lower'], ['x', 'y']]), 1e-9);
        // One of two blanks in the first sequence → 0.5 * 0.5 = 0.25.
        $this->assertEqualsWithDelta(0.25, scoring::score_coding($seqs, [['split', 'X'], ['x', 'y']]), 1e-9);
        $this->assertEqualsWithDelta(0.0, scoring::score_coding($seqs, []), 1e-9);
        $this->assertEqualsWithDelta(0.0, scoring::score_coding([], []), 1e-9);
    }

    /**
     * Coding values compare as strings, so "0" matches integer-like placements.
     */
    public function test_score_coding_string_compare(): void {
        $seqs = [['blanks' => ['0', '1']]];
        $this->assertEqualsWithDelta(1.0, scoring::score_coding($seqs, [[0, 1]]), 1e-9);
    }

    /**
     * The solution map covers every letter cell of every word.
     */
    public function test_crossword_solution(): void {
        $words = [
            ['word' => 'TOKEN', 'direction' => 'across', 'row' => 0, 'col' => 0],
            ['word' => 'CORPUS', 'direction' => 'down', 'row' => 0, 'col' => 2],
        ];
        $solution = scoring::crossword_solution($words);
        $this->assertSame('T', $solution['0-0']);
        $this->assertSame('N', $solution['0-4']);
        // CORPUS goes down from row 0, col 2; its 'C' overlaps TOKEN's 'K' cell.
        $this->assertSame('C', $solution['0-2']);
        $this->assertSame('S', $solution['5-2']);
    }

    /**
     * Crossword correctness is a 0–1 fraction and case-insensitive.
     */
    public function test_crossword_correct_fraction(): void {
        $words = [['word' => 'TOKEN', 'direction' => 'across', 'row' => 0, 'col' => 0]];
        $full = ['0-0' => 'T', '0-1' => 'o', '0-2' => 'K', '0-3' => 'e', '0-4' => 'N'];
        $this->assertEqualsWithDelta(1.0, scoring::crossword_correct_fraction($words, $full), 1e-9);
        $this->assertTrue(scoring::crossword_solved($words, $full));

        $partial = ['0-0' => 'T', '0-1' => 'O'];
        $this->assertEqualsWithDelta(2 / 5, scoring::crossword_correct_fraction($words, $partial), 1e-9);
        $this->assertFalse(scoring::crossword_solved($words, $partial));

        $this->assertEqualsWithDelta(0.0, scoring::crossword_correct_fraction([], []), 1e-9);
    }

    /**
     * Full solves take finish-rank places; partials are capped below 0.5.
     */
    public function test_score_crossword(): void {
        // Full solve → rank by how many fully solved before.
        $this->assertEqualsWithDelta(1.0, scoring::score_crossword(1.0, 0), 1e-9);
        $this->assertEqualsWithDelta(1.0, scoring::score_crossword(1.0, 2), 1e-9);
        $this->assertEqualsWithDelta(0.75, scoring::score_crossword(1.0, 3), 1e-9);
        $this->assertEqualsWithDelta(0.75, scoring::score_crossword(1.0, 9), 1e-9);
        $this->assertEqualsWithDelta(0.5, scoring::score_crossword(1.0, 10), 1e-9);

        // Partial → fraction × 0.5, capped at 0.49.
        $this->assertEqualsWithDelta(0.0, scoring::score_crossword(0.0, 0), 1e-9);
        $this->assertEqualsWithDelta(0.25, scoring::score_crossword(0.5, 0), 1e-9);
        $this->assertEqualsWithDelta(0.48, scoring::score_crossword(0.96, 0), 1e-9);
        $this->assertEqualsWithDelta(0.49, scoring::score_crossword(0.99, 0), 1e-9); // would be 0.495, capped.
    }

    /**
     * Invariant: no partial solve can match or beat the lowest full solve (0.5).
     */
    public function test_crossword_partial_never_reaches_full(): void {
        $lowestfull = scoring::score_crossword(1.0, 1000); // worst full-solve rank = 0.5.
        foreach ([0.0, 0.5, 0.9, 0.99, 0.999] as $frac) {
            $this->assertLessThan($lowestfull, scoring::score_crossword($frac, 0));
        }
    }

    /**
     * Finish-rank points: 1st–3rd = 1.0, 4th–10th = 0.75, rest = 0.5.
     */
    public function test_crossword_rank_points(): void {
        $this->assertEqualsWithDelta(1.0, scoring::crossword_rank_points(0), 1e-9);
        $this->assertEqualsWithDelta(1.0, scoring::crossword_rank_points(2), 1e-9);
        $this->assertEqualsWithDelta(0.75, scoring::crossword_rank_points(3), 1e-9);
        $this->assertEqualsWithDelta(0.75, scoring::crossword_rank_points(9), 1e-9);
        $this->assertEqualsWithDelta(0.5, scoring::crossword_rank_points(10), 1e-9);
        $this->assertEqualsWithDelta(0.5, scoring::crossword_rank_points(50), 1e-9);
    }
}
