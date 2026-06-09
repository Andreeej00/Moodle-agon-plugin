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

use mod_agon\local\attempt;

/**
 * Tests for the attempt lifecycle (DB-backed).
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\local\attempt
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_test extends \advanced_testcase {
    /** @var \stdClass The agon instance under test. */
    private $agon;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $crossword = json_encode(['words' => [
            ['number' => 1, 'word' => 'TOKEN', 'direction' => 'across', 'row' => 0, 'col' => 0],
        ]]);
        $question = json_encode(['questions' => [
            ['question' => 'Pick B', 'options' => ['A', 'B', 'C'], 'correct' => 1, 'explanation' => 'B is right.'],
        ]]);
        $coding = json_encode(['sequences' => [
            ['title' => 'S1', 'code' => 'a = ____', 'blanks' => ['x'], 'options' => ['x', 'y']],
            ['title' => 'S2', 'code' => 'b = ____', 'blanks' => ['z'], 'options' => ['z', 'w']],
        ]]);
        $this->agon = $this->getDataGenerator()->create_module('agon', [
            'course' => $course->id,
            'contentcrossword' => $crossword,
            'contentquestion' => $question,
            'contentcoding' => $coding,
        ]);
    }

    /** @return array<string,string> A fully correct TOKEN entry map. */
    private function full_crossword(): array {
        return ['0-0' => 'T', '0-1' => 'O', '0-2' => 'K', '0-3' => 'E', '0-4' => 'N'];
    }

    public function test_start_is_idempotent(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $first = attempt::start($this->agon->id, $user->id);
        $second = attempt::start($this->agon->id, $user->id);
        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, $DB->count_records('agon_attempt', ['agonid' => $this->agon->id]));
        $this->assertSame(attempt::STATE_INPROGRESS, $first->state);
        $this->assertGreaterThan(0, $first->timestart);
    }

    public function test_submit_question(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);

        $this->assertEqualsWithDelta(1.0, attempt::submit_game($a, 'question', ['selected' => 1]), 1e-9);
        $row = $DB->get_record('agon_attempt', ['id' => $a->id]);
        $this->assertEqualsWithDelta(1.0, (float)$row->scorequestion, 1e-9);
        $this->assertEqualsWithDelta(1.0, (float)$row->score, 1e-9);
    }

    public function test_submit_question_wrong(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        $this->assertEqualsWithDelta(0.0, attempt::submit_game($a, 'question', ['selected' => 0]), 1e-9);
    }

    public function test_submit_coding_full_and_partial(): void {
        $a = attempt::start($this->agon->id, $this->getDataGenerator()->create_user()->id);
        $this->assertEqualsWithDelta(1.0, attempt::submit_game($a, 'coding', ['answers' => [['x'], ['z']]]), 1e-9);

        $b = attempt::start($this->agon->id, $this->getDataGenerator()->create_user()->id);
        // First sequence right, second wrong → 0.5.
        $this->assertEqualsWithDelta(0.5, attempt::submit_game($b, 'coding', ['answers' => [['x'], ['w']]]), 1e-9);
    }

    public function test_submit_crossword_full_solves_take_finish_rank(): void {
        $scores = [];
        for ($i = 0; $i < 4; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $a = attempt::start($this->agon->id, $user->id);
            $scores[] = attempt::submit_game($a, 'crossword', ['entries' => $this->full_crossword()]);
        }
        // First three full solvers = 1.0, fourth = 0.75.
        $this->assertEqualsWithDelta([1.0, 1.0, 1.0, 0.75], $scores, 1e-9);
    }

    public function test_submit_crossword_partial_is_capped(): void {
        $a = attempt::start($this->agon->id, $this->getDataGenerator()->create_user()->id);
        // 2 of 5 letters correct → 0.4 × 0.5 = 0.2.
        $score = attempt::submit_game($a, 'crossword', ['entries' => ['0-0' => 'T', '0-1' => 'O']]);
        $this->assertEqualsWithDelta(0.2, $score, 1e-9);
    }

    public function test_total_accumulates_across_games_then_finishes(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        attempt::submit_game($a, 'crossword', ['entries' => $this->full_crossword()]);
        attempt::submit_game($a, 'question', ['selected' => 1]);
        attempt::submit_game($a, 'coding', ['answers' => [['x'], ['z']]]);

        $row = $DB->get_record('agon_attempt', ['id' => $a->id]);
        $this->assertEqualsWithDelta(3.0, (float)$row->score, 1e-9);

        $finished = attempt::finish($a);
        $this->assertSame(attempt::STATE_FINISHED, $finished->state);
        $this->assertGreaterThan(0, $finished->timefinish);
        // Finishing must not clobber the scores.
        $this->assertEqualsWithDelta(3.0, (float)$finished->score, 1e-9);
    }

    public function test_cannot_submit_after_finish(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        attempt::finish($a);
        $this->expectException(\moodle_exception::class);
        attempt::submit_game($a, 'question', ['selected' => 1]);
    }

    public function test_cannot_resubmit_a_game(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        attempt::submit_game($a, 'question', ['selected' => 1]);
        $this->assertEquals(['question'], attempt::submitted_games(attempt::get($a->id)));
        $this->expectException(\moodle_exception::class);
        attempt::submit_game($a, 'question', ['selected' => 0]);
    }
}
