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

use mod_agon\local\content;

/**
 * Tests for content loading and the answer-split (DB-backed).
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\local\content
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_test extends \advanced_testcase {
    /** @var \stdClass The agon instance under test. */
    private $agon;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $crossword = json_encode(['words' => [
            ['number' => 1, 'word' => 'TOKEN', 'clue' => 'A unit', 'direction' => 'across', 'row' => 0, 'col' => 0],
        ]]);
        $question = json_encode(['questions' => [
            ['question' => 'Pick B', 'options' => ['A', 'B', 'C'], 'correct' => 1, 'explanation' => 'B is right.'],
        ]]);
        $coding = json_encode(['sequences' => [
            ['title' => 'S1', 'code' => 'a = ____', 'blanks' => ['x'], 'options' => ['x', 'y'], 'explanation' => 'x fits.'],
        ]]);
        $this->agon = $this->getDataGenerator()->create_module('agon', [
            'course' => $course->id,
            'contentcrossword' => $crossword,
            'contentquestion' => $question,
            'contentcoding' => $coding,
        ]);
    }

    public function test_public_for_render_strips_every_answer(): void {
        $public = content::public_for_render($this->agon->id);

        $word = $public['crossword']['words'][0];
        $this->assertArrayNotHasKey('word', $word);
        $this->assertSame(5, $word['length']);
        $this->assertSame('A unit', $word['clue']);

        $question = $public['questions'][0];
        $this->assertSame(['A', 'B', 'C'], $question['options']);
        $this->assertArrayNotHasKey('correct', $question);
        $this->assertArrayNotHasKey('explanation', $question);

        $seq = $public['coding']['sequences'][0];
        $this->assertSame(['x', 'y'], $seq['options']);
        $this->assertArrayNotHasKey('blanks', $seq);
        $this->assertArrayNotHasKey('explanation', $seq);
    }

    public function test_feedback_reveals_answers(): void {
        $cw = content::feedback($this->agon->id, 'crossword');
        $this->assertSame('T', $cw['solution']['0-0']);
        $this->assertSame('N', $cw['solution']['0-4']);

        $q = content::feedback($this->agon->id, 'question');
        $this->assertSame(1, $q['correct']);
        $this->assertSame('B is right.', $q['explanation']);

        $code = content::feedback($this->agon->id, 'coding');
        $this->assertSame(['x'], $code['sequences'][0]['blanks']);
        $this->assertSame('x fits.', $code['sequences'][0]['explanation']);
    }

    public function test_hint_question_returns_explanation(): void {
        $hint = content::hint($this->agon->id, 'question');
        $this->assertSame('question', $hint['type']);
        $this->assertSame('B is right.', $hint['explanation']);
    }

    public function test_hint_crossword_targets_an_unfilled_cell(): void {
        $hint = content::hint($this->agon->id, 'crossword', ['filled' => ['0-0', '0-1', '0-2', '0-3']]);
        $this->assertSame('crossword', $hint['type']);
        $this->assertSame('0-4', $hint['rc']);
        $this->assertSame('N', $hint['letter']);
    }

    public function test_hint_coding_returns_the_next_blank(): void {
        $hint = content::hint($this->agon->id, 'coding', ['filled' => []]);
        $this->assertSame('coding', $hint['type']);
        $this->assertSame(0, $hint['seq']);
        $this->assertSame(0, $hint['b']);
        $this->assertSame('x', $hint['value']);
    }
}
