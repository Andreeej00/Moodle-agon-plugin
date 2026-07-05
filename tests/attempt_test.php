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

        $this->assertEqualsWithDelta(1.0, attempt::submit_game($a, 'question', ['selected' => 1])['score'], 1e-9);
        $row = $DB->get_record('agon_attempt', ['id' => $a->id]);
        $this->assertEqualsWithDelta(1.0, (float)$row->scorequestion, 1e-9);
        $this->assertEqualsWithDelta(1.0, (float)$row->score, 1e-9);
    }

    public function test_submit_question_wrong(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        $this->assertEqualsWithDelta(0.0, attempt::submit_game($a, 'question', ['selected' => 0])['score'], 1e-9);
    }

    public function test_submit_coding_full_and_partial(): void {
        $a = attempt::start($this->agon->id, $this->getDataGenerator()->create_user()->id);
        $this->assertEqualsWithDelta(1.0, attempt::submit_game($a, 'coding', ['answers' => [['x'], ['z']]])['score'], 1e-9);

        $b = attempt::start($this->agon->id, $this->getDataGenerator()->create_user()->id);
        // First sequence right, second wrong → 0.5.
        $this->assertEqualsWithDelta(0.5, attempt::submit_game($b, 'coding', ['answers' => [['x'], ['w']]])['score'], 1e-9);
    }

    public function test_submit_crossword_full_solves_take_finish_rank(): void {
        $scores = [];
        for ($i = 0; $i < 4; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $a = attempt::start($this->agon->id, $user->id);
            $scores[] = attempt::submit_game($a, 'crossword', ['entries' => $this->full_crossword()])['score'];
        }
        // First three full solvers = 1.0, fourth = 0.75.
        $this->assertEqualsWithDelta([1.0, 1.0, 1.0, 0.75], $scores, 1e-9);
    }

    public function test_submit_crossword_partial_scores_per_word(): void {
        // Custom (default) grading now gives partial credit per WHOLE word: (words right / total) × 0.5.
        $course = $this->getDataGenerator()->create_course();
        $crossword = json_encode(['words' => [
            ['number' => 1, 'word' => 'CAT', 'direction' => 'across', 'row' => 0, 'col' => 0],
            ['number' => 2, 'word' => 'DOG', 'direction' => 'across', 'row' => 1, 'col' => 0],
        ]]);
        $agon = $this->getDataGenerator()->create_module('agon',
            ['course' => $course->id, 'contentcrossword' => $crossword]);

        // CAT fully right, DOG has a wrong letter → 1 of 2 words = 0.5 fraction × 0.5 = 0.25.
        $a = attempt::start($agon->id, $this->getDataGenerator()->create_user()->id);
        $onlycat = ['0-0' => 'C', '0-1' => 'A', '0-2' => 'T', '1-0' => 'D', '1-1' => 'X', '1-2' => 'G'];
        $this->assertEqualsWithDelta(0.25, attempt::submit_game($a, 'crossword', ['entries' => $onlycat])['score'], 1e-9);

        // No word fully correct (both partial) → 0.0, even though 4 of 6 letters are right.
        $b = attempt::start($agon->id, $this->getDataGenerator()->create_user()->id);
        $noword = ['0-0' => 'C', '0-1' => 'A', '1-0' => 'D', '1-1' => 'O'];
        $this->assertEqualsWithDelta(0.0, attempt::submit_game($b, 'crossword', ['entries' => $noword])['score'], 1e-9);
    }

    public function test_submit_crossword_regular_grading_scores_per_word(): void {
        // A separate instance graded "regular": score = fraction of whole words.
        $course = $this->getDataGenerator()->create_course();
        $crossword = json_encode(['grading' => 'regular', 'words' => [
            ['number' => 1, 'word' => 'CAT', 'direction' => 'across', 'row' => 0, 'col' => 0],
            ['number' => 2, 'word' => 'DOG', 'direction' => 'across', 'row' => 1, 'col' => 0],
        ]]);
        $agon = $this->getDataGenerator()->create_module('agon',
            ['course' => $course->id, 'contentcrossword' => $crossword]);
        $a = attempt::start($agon->id, $this->getDataGenerator()->create_user()->id);

        // CAT fully right, DOG has one wrong letter → 1 of 2 words = 0.5 (regular has no × 0.5 partial).
        $entries = ['0-0' => 'C', '0-1' => 'A', '0-2' => 'T', '1-0' => 'D', '1-1' => 'X', '1-2' => 'G'];
        $this->assertEqualsWithDelta(0.5, attempt::submit_game($a, 'crossword', ['entries' => $entries])['score'], 1e-9);
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
        attempt::submit_game($a, 'crossword', ['entries' => $this->full_crossword()]);
        attempt::submit_game($a, 'question', ['selected' => 1]);
        attempt::submit_game($a, 'coding', ['answers' => [['x'], ['z']]]);
        attempt::finish($a);
        $this->expectException(\moodle_exception::class);
        attempt::submit_game($a, 'question', ['selected' => 1]);
    }

    public function test_finish_requires_every_enabled_game(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        attempt::submit_game($a, 'question', ['selected' => 1]); // crossword + coding still missing.
        $this->expectException(\moodle_exception::class);
        attempt::finish($a);
    }

    public function test_finish_skips_enabled_but_empty_games(): void {
        // Crossword/coding toggles default to on, but their content defaults to '' —
        // they are not playable, so only the question gates the finish.
        $instance = $this->getDataGenerator()->create_module('agon', [
            'course' => $this->agon->course,
            'contentquestion' => json_encode(['questions' => [
                ['question' => 'Pick B', 'options' => ['A', 'B'], 'correct' => 1],
            ]]),
        ]);
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($instance->id, $user->id);
        attempt::submit_game($a, 'question', ['selected' => 1]);
        $fin = attempt::finish($a);
        $this->assertSame(attempt::STATE_FINISHED, $fin->state);
    }

    public function test_unusable_hint_is_not_spent(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        // Every crossword cell already filled → nothing to reveal, so the hint is refunded.
        $hint = attempt::use_hint($a, 'crossword', ['filled' => array_keys($this->full_crossword())]);
        $this->assertSame([], $hint['cells']);
        $this->assertSame([], attempt::hints_used(attempt::get($a->id)));
        // It can still be used afterwards: real cells are revealed and the hint is now spent.
        $again = attempt::use_hint($a, 'crossword', ['filled' => ['0-0']]);
        $this->assertNotEmpty($again['cells']);
        $this->assertSame(['crossword'], attempt::hints_used(attempt::get($a->id)));
    }

    public function test_cannot_resubmit_a_game(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        attempt::submit_game($a, 'question', ['selected' => 1]);
        $this->assertEquals(['question'], attempt::submitted_games(attempt::get($a->id)));
        $this->expectException(\moodle_exception::class);
        attempt::submit_game($a, 'question', ['selected' => 0]);
    }

    public function test_hint_is_once_per_attempt(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        $hint = attempt::use_hint($a, 'question');
        $this->assertSame('question', $hint['type']);
        $this->assertEquals(['question'], attempt::hints_used(attempt::get($a->id)));
        // The one hint is spent for the whole attempt — a different game is refused too.
        $this->expectException(\moodle_exception::class);
        attempt::use_hint($a, 'crossword', ['filled' => ['0-0']]);
    }

    public function test_regular_grading_ignores_finish_rank(): void {
        global $DB;
        // Under regular grading a full solve is 1.0 no matter how many solved first.
        $DB->set_field('agon', 'contentcrossword', json_encode(['grading' => 'regular', 'words' => [
            ['number' => 1, 'word' => 'TOKEN', 'direction' => 'across', 'row' => 0, 'col' => 0],
        ]]), ['id' => $this->agon->id]);
        $scores = [];
        for ($i = 0; $i < 5; $i++) {
            $a = attempt::start($this->agon->id, $this->getDataGenerator()->create_user()->id);
            $scores[] = attempt::submit_game($a, 'crossword', ['entries' => $this->full_crossword()])['score'];
        }
        $this->assertEqualsWithDelta([1.0, 1.0, 1.0, 1.0, 1.0], $scores, 1e-9);
    }

    public function test_summary_is_webservice_shaped(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        attempt::submit_game($a, 'question', ['selected' => 1]);
        $s = attempt::summary(attempt::get($a->id));
        $this->assertSame((int)$a->id, $s['attemptid']);
        $this->assertSame(attempt::STATE_INPROGRESS, $s['state']);
        $this->assertEqualsWithDelta(1.0, $s['scorequestion'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $s['score'], 1e-9);
        $this->assertSame(['question'], $s['submittedgames']);
        $this->assertSame([], $s['hintsused']);
        $this->assertIsInt($s['timestart']);
        $this->assertSame(0, $s['timefinish']);
    }

    public function test_finish_is_idempotent(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        attempt::submit_game($a, 'crossword', ['entries' => $this->full_crossword()]);
        attempt::submit_game($a, 'question', ['selected' => 1]);
        attempt::submit_game($a, 'coding', ['answers' => [['x'], ['z']]]);
        $first = attempt::finish($a);
        $second = attempt::finish($a);
        $this->assertSame(attempt::STATE_FINISHED, $second->state);
        $this->assertEquals($first->timefinish, $second->timefinish);
    }

    public function test_unknown_game_is_a_coding_error(): void {
        $user = $this->getDataGenerator()->create_user();
        $a = attempt::start($this->agon->id, $user->id);
        try {
            attempt::submit_game($a, 'poetry', []);
            $this->fail('Expected coding_exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('poetry', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        attempt::use_hint($a, 'poetry');
    }

    public function test_progress_lists_tolerate_garbage_json(): void {
        // A corrupted column must read as "nothing yet", never fatal.
        $broken = (object)['submittedgames' => '{oops', 'hintsused' => null];
        $this->assertSame([], attempt::submitted_games($broken));
        $this->assertSame([], attempt::hints_used($broken));
    }
}
