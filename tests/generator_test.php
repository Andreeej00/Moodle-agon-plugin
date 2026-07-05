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

/**
 * Tests for the mod_agon test data generator itself.
 *
 * @package     mod_agon
 * @category    test
 * @coversNothing
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generator_test extends \advanced_testcase {
    public function test_create_instance_defaults_and_overrides(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Defaults: all three games toggled on, content empty.
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $row = $DB->get_record('agon', ['id' => $agon->id], '*', MUST_EXIST);
        $this->assertEquals(1, $row->gamecrossword);
        $this->assertEquals(1, $row->gamequestion);
        $this->assertEquals(1, $row->gamecoding);
        $this->assertSame('', (string)$row->contentcrossword);
        $this->assertNotEmpty($row->name);
        $this->assertNotEmpty(get_coursemodule_from_instance('agon', $agon->id));

        // Overrides are respected.
        $json = json_encode(['questions' => [['question' => 'Q', 'options' => ['a', 'b'], 'correct' => 0]]]);
        $agon2 = $this->getDataGenerator()->create_module('agon', [
            'course' => $course->id,
            'gamecrossword' => 0,
            'contentquestion' => $json,
        ]);
        $row2 = $DB->get_record('agon', ['id' => $agon2->id], '*', MUST_EXIST);
        $this->assertEquals(0, $row2->gamecrossword);
        $this->assertSame($json, $row2->contentquestion);
    }
}
