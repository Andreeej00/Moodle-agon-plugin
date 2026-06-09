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

/**
 * mod_agon test data generator.
 *
 * @package     mod_agon
 * @category    test
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * mod_agon module instance generator.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_agon_generator extends testing_module_generator {
    /**
     * Create a new agon instance, defaulting the game toggles and content.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'gamecrossword' => 1,
            'gamequestion' => 1,
            'gamecoding' => 1,
            'contentcrossword' => '',
            'contentquestion' => '',
            'contentcoding' => '',
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->$name)) {
                $record->$name = $value;
            }
        }
        return parent::create_instance($record, (array)$options);
    }
}
