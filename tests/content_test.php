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

        // Coding is METADATA only up front (title + line count); code/options are lazy-loaded.
        $seq = $public['coding']['sequences'][0];
        $this->assertSame('S1', $seq['title']);
        $this->assertSame(1, $seq['lines']);
        $this->assertArrayNotHasKey('code', $seq);
        $this->assertArrayNotHasKey('options', $seq);
        $this->assertArrayNotHasKey('blanks', $seq);
    }

    public function test_coding_sequence_public_serves_one_sequence_without_answers(): void {
        $seq = content::coding_sequence_public($this->agon->id, 0);
        $this->assertSame('S1', $seq['title']);
        $this->assertSame('a = ____', $seq['code']);
        $this->assertSame(['x', 'y'], $seq['options']);
        $this->assertArrayNotHasKey('blanks', $seq);
        $this->assertNull(content::coding_sequence_public($this->agon->id, 5));
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

    public function test_hint_question_eliminates_wrong_answers(): void {
        // 3 options, correct = 1 → floor(3/2) = 1 wrong option removed (never the correct one).
        $hint = content::hint($this->agon->id, 'question');
        $this->assertSame('question', $hint['type']);
        $this->assertCount(1, $hint['remove']);
        $this->assertNotContains(1, $hint['remove']);
    }

    public function test_hint_crossword_reveals_random_unfilled_letters(): void {
        // One word (TOKEN) → ceil(1/2) = 1 letter revealed, at the only unfilled cell.
        $hint = content::hint($this->agon->id, 'crossword', ['filled' => ['0-0', '0-1', '0-2', '0-3']]);
        $this->assertSame('crossword', $hint['type']);
        $this->assertSame([['rc' => '0-4', 'letter' => 'N']], $hint['cells']);
    }

    public function test_hint_coding_marks_the_correct_options(): void {
        $hint = content::hint($this->agon->id, 'coding', ['seq' => 0]);
        $this->assertSame('coding', $hint['type']);
        $this->assertSame(0, $hint['seq']);
        $this->assertSame(['x'], $hint['corrects']);
    }

    public function test_playable_games_requires_toggle_and_content(): void {
        $agon = (object)[
            // Enabled but empty content → not playable.
            'gamecrossword' => 1, 'contentcrossword' => '',
            // Enabled with real content → playable.
            'gamequestion' => 1, 'contentquestion' => json_encode(['questions' => [['question' => 'Q']]]),
            // Real content but toggled off → not playable.
            'gamecoding' => 0, 'contentcoding' => json_encode(['sequences' => [['code' => 'x = ____']]]),
        ];
        $this->assertSame(
            ['crossword' => false, 'question' => true, 'coding' => false],
            content::playable_games($agon)
        );

        // Invalid JSON behaves like empty content.
        $agon->contentcrossword = '{not json';
        $agon->gamecrossword = 1;
        $this->assertFalse(content::playable_games($agon)['crossword']);
    }

    public function test_crossword_grading_defaults_to_custom(): void {
        // The setUp crossword has no 'grading' key.
        $this->assertSame('custom', content::raw($this->agon->id)['crossword']['grading']);
        $this->assertSame('custom', content::public_for_render($this->agon->id)['crossword']['grading']);
    }

    public function test_save_game_stores_valid_content_and_grading(): void {
        content::save_game($this->agon->id, 'crossword', json_encode(['grading' => 'regular', 'words' => [
            ['number' => 1, 'word' => 'CAT', 'clue' => 'Pet', 'direction' => 'across', 'row' => 0, 'col' => 0],
        ]]));
        $raw = content::raw($this->agon->id);
        $this->assertSame('regular', $raw['crossword']['grading']);
        $this->assertSame('CAT', $raw['crossword']['words'][0]['word']);
    }

    public function test_save_game_rejects_invalid_content(): void {
        $this->expectException(\moodle_exception::class);
        content::save_game($this->agon->id, 'question', json_encode(['questions' => []]));
    }

    public function test_validate_game(): void {
        $this->assertNull(content::validate_game('question',
            ['questions' => [['question' => 'Q', 'options' => ['a', 'b'], 'correct' => 0]]]));
        $this->assertSame('questions', content::validate_game('question', ['questions' => []]));
        $this->assertSame('words', content::validate_game('crossword',
            ['words' => [['word' => '', 'direction' => 'across', 'row' => 0, 'col' => 0]]]));
        $this->assertNull(content::validate_game('crossword',
            ['words' => [['word' => 'CAT', 'direction' => 'across', 'row' => 0, 'col' => 0]]]));
        $this->assertNull(content::validate_game('coding',
            ['sequences' => [['code' => 'a = ____', 'blanks' => ['x'], 'options' => ['x', 'y']]]]));
        $this->assertSame('sequences', content::validate_game('coding', ['sequences' => [['code' => '']]]));
        // Not-decoded / unknown-game payloads name what is missing.
        $this->assertSame('contentquestion', content::validate_game('question', 'not an array'));
        $this->assertSame('poetry', content::validate_game('poetry', ['anything' => true]));
    }

    public function test_validate_game_rejects_an_unanswerable_question(): void {
        // 'correct' must point at an existing option, or no student can ever score.
        $this->assertSame('questions', content::validate_game('question',
            ['questions' => [['question' => 'Q', 'options' => ['a', 'b'], 'correct' => 5]]]));
        $this->assertSame('questions', content::validate_game('question',
            ['questions' => [['question' => 'Q', 'options' => ['a', 'b'], 'correct' => -1]]]));
    }

    public function test_validate_game_coding_blanks_must_match_the_code(): void {
        // Two ____ but only one answer: the second blank could never be graded.
        $this->assertSame('sequences', content::validate_game('coding',
            ['sequences' => [['code' => 'a = ____; b = ____', 'blanks' => ['x'], 'options' => ['x']]]]));
        // Answer not offered as an option: the blank could never be filled correctly.
        $this->assertSame('sequences', content::validate_game('coding',
            ['sequences' => [['code' => 'a = ____', 'blanks' => ['x'], 'options' => ['y', 'z']]]]));
        // No ____ at all: nothing to play.
        $this->assertSame('sequences', content::validate_game('coding',
            ['sequences' => [['code' => 'a = 1', 'blanks' => [], 'options' => ['x']]]]));
        // Numeric blank vs string option still matches (values compare as strings).
        $this->assertNull(content::validate_game('coding',
            ['sequences' => [['code' => 'n = ____', 'blanks' => [0], 'options' => ['0', '1']]]]));
    }

    public function test_validate_game_crossword_rejects_negative_coordinates(): void {
        // The player draws from (0,0); a negative coordinate would break the grid.
        $this->assertSame('words', content::validate_game('crossword',
            ['words' => [['word' => 'CAT', 'direction' => 'down', 'row' => -1, 'col' => 0]]]));
    }

    public function test_meta_returns_subject_and_week(): void {
        global $DB;
        $DB->set_field('agon', 'contentcrossword', json_encode([
            'subject' => 'NLP', 'week' => 4, 'topic' => 'Tokenization', 'words' => [
                ['number' => 1, 'word' => 'TOKEN', 'clue' => 'A unit', 'direction' => 'across', 'row' => 0, 'col' => 0],
            ]]), ['id' => $this->agon->id]);
        $meta = content::meta($this->agon->id);
        $this->assertSame('NLP', $meta['subject']);
        $this->assertEquals(4, $meta['week']);
        $this->assertSame('Tokenization', $meta['topic']);
    }

    public function test_raw_tolerates_invalid_stored_json(): void {
        global $DB;
        $DB->set_field('agon', 'contentcrossword', '{broken', ['id' => $this->agon->id]);
        $DB->set_field('agon', 'contentquestion', '', ['id' => $this->agon->id]);
        $raw = content::raw($this->agon->id);
        $this->assertSame([], $raw['crossword']['words']);
        $this->assertSame([], $raw['questions']);
        $this->assertSame('custom', $raw['crossword']['grading']);
    }

    public function test_public_for_render_counts_nonempty_code_lines(): void {
        global $DB;
        // Three real lines with a stray blank line — the metadata reports 3 (and
        // at least 1 even for pathological content), for the timer budget.
        $DB->set_field('agon', 'contentcoding', json_encode(['sequences' => [
            ['title' => 'S', 'code' => "a = ____\n\nb = ____\nc = ____", 'blanks' => ['x', 'y', 'z'], 'options' => ['x', 'y', 'z']],
            ['title' => 'T', 'code' => ' ', 'blanks' => ['q'], 'options' => ['q']],
        ]]), ['id' => $this->agon->id]);
        $seqs = content::public_for_render($this->agon->id)['coding']['sequences'];
        $this->assertSame(3, $seqs[0]['lines']);
        $this->assertSame(1, $seqs[1]['lines']);
    }

    public function test_hint_coding_out_of_range_sequence_falls_back_to_first(): void {
        $hint = content::hint($this->agon->id, 'coding', ['seq' => 99]);
        $this->assertSame(0, $hint['seq']);
        $this->assertSame(['x'], $hint['corrects']);
    }

    public function test_unknown_game_shapes_are_empty(): void {
        $this->assertSame([], content::feedback($this->agon->id, 'poetry'));
        $this->assertSame([], content::hint($this->agon->id, 'poetry'));
        $this->assertNull(content::coding_sequence_public($this->agon->id, -1));
    }

    public function test_save_game_unknown_game_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        content::save_game($this->agon->id, 'poetry', '{}');
    }
}
