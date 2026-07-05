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

namespace mod_agon\external;

use core_external\external_api;

/**
 * Tests for the teacher-side (authoring) web services: save_content, ai_prompt,
 * ai_generate, fetch_source and extract_file.
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\external\save_content
 * @covers      \mod_agon\external\ai_prompt
 * @covers      \mod_agon\external\ai_generate
 * @covers      \mod_agon\external\fetch_source
 * @covers      \mod_agon\external\extract_file
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class authoring_services_test extends \advanced_testcase {
    /** @var \stdClass The course holding the activity. */
    private $course;
    /** @var \stdClass The agon instance under test. */
    private $agon;
    /** @var \stdClass An editing teacher (holds mod/agon:manage). */
    private $teacher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->agon = $this->getDataGenerator()->create_module('agon', ['course' => $this->course->id]);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
    }

    public function test_save_content_stores_valid_json(): void {
        global $DB;
        $this->setUser($this->teacher);
        $json = json_encode(['questions' => [
            ['question' => 'Pick A', 'options' => ['A', 'B', 'C'], 'correct' => 0, 'explanation' => 'A.'],
        ]]);

        $res = external_api::clean_returnvalue(
            save_content::execute_returns(),
            save_content::execute($this->agon->cmid, 'question', $json));
        $this->assertSame('ok', $res['status']);

        $stored = $DB->get_field('agon', 'contentquestion', ['id' => $this->agon->id]);
        $decoded = json_decode($stored, true);
        $this->assertSame('Pick A', $decoded['questions'][0]['question']);
    }

    public function test_save_content_rejects_invalid_json(): void {
        $this->setUser($this->teacher);
        try {
            // 'correct' points past the options — an unanswerable question.
            save_content::execute($this->agon->cmid, 'question', json_encode(['questions' => [
                ['question' => 'Q', 'options' => ['A', 'B'], 'correct' => 5],
            ]]));
            $this->fail('Expected invalidgamejson');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidgamejson', $e->errorcode);
        }
    }

    public function test_save_content_rejects_unknown_game(): void {
        $this->setUser($this->teacher);
        $this->expectException(\invalid_parameter_exception::class);
        save_content::execute($this->agon->cmid, 'poetry', '{}');
    }

    public function test_authoring_requires_the_manage_capability(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        try {
            save_content::execute($this->agon->cmid, 'question', '{"questions":[]}');
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->expectException(\required_capability_exception::class);
        ai_prompt::execute($this->agon->cmid, 'question', 'Material');
    }

    public function test_ai_prompt_builds_the_grounded_prompt(): void {
        $this->setUser($this->teacher);
        $res = external_api::clean_returnvalue(
            ai_prompt::execute_returns(),
            ai_prompt::execute($this->agon->cmid, 'question', 'Photosynthesis basics.', 3, 4));
        $this->assertStringContainsString('exactly 3 multiple-choice', $res['prompt']);
        $this->assertStringContainsString('EXACTLY 4 options', $res['prompt']);
        $this->assertStringContainsString('Photosynthesis basics.', $res['prompt']);
    }

    public function test_ai_generate_respects_the_site_switch(): void {
        $this->setUser($this->teacher);
        set_config('aienable', 0, 'mod_agon');
        try {
            ai_generate::execute($this->agon->cmid, 'question', 'Material', 'google', '', 'key');
            $this->fail('Expected aidisabled');
        } catch (\moodle_exception $e) {
            $this->assertSame('aidisabled', $e->errorcode);
        }
    }

    public function test_ai_generate_returns_the_content_json(): void {
        $this->setUser($this->teacher);
        set_config('aienable', 1, 'mod_agon');
        \curl::mock_response(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '{"questions":[{"question":"Q"}]}']]]]],
        ]));
        $res = external_api::clean_returnvalue(
            ai_generate::execute_returns(),
            ai_generate::execute($this->agon->cmid, 'question', 'Material', 'google', '', 'teacher-key', 3, 4));
        $this->assertSame('{"questions":[{"question":"Q"}]}', $res['content']);
    }

    public function test_fetch_source_validates_the_link(): void {
        $this->setUser($this->teacher);
        try {
            fetch_source::execute($this->agon->cmid, 'https://evil.example.com/document/d/abc');
            $this->fail('Expected aifetchfailed');
        } catch (\moodle_exception $e) {
            $this->assertSame('aifetchfailed', $e->errorcode);
        }

        \curl::mock_response('Doc text for the games.');
        $res = external_api::clean_returnvalue(
            fetch_source::execute_returns(),
            fetch_source::execute($this->agon->cmid, 'https://docs.google.com/document/d/abc123/edit'));
        $this->assertSame('Doc text for the games.', $res['text']);
    }

    public function test_extract_file_reads_a_pptx(): void {
        $this->setUser($this->teacher);

        $tmp = tempnam(sys_get_temp_dir(), 'agonpptx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('ppt/slides/slide1.xml', '<p:sld><a:t>Uploaded slide text</a:t></p:sld>');
        $zip->close();
        $b64 = base64_encode(file_get_contents($tmp));
        unlink($tmp);

        $res = external_api::clean_returnvalue(
            extract_file::execute_returns(),
            extract_file::execute($this->agon->cmid, 'lecture.pptx', $b64));
        $this->assertStringContainsString('Uploaded slide text', $res['text']);

        // Unsupported extensions are refused with guidance.
        try {
            extract_file::execute($this->agon->cmid, 'notes.txt', base64_encode('plain'));
            $this->fail('Expected aifileunsupported');
        } catch (\moodle_exception $e) {
            $this->assertSame('aifileunsupported', $e->errorcode);
        }
    }
}
