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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_agon\local\content;

/**
 * Web service: fetch one coding sequence (title + code + options, no answers).
 *
 * The lazy-load endpoint — the player pulls sequences one at a time as the student
 * advances, so the full set of code/options is never present in the page at once.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_sequence extends external_api {
    use uses_agon_context;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the agon activity'),
            'index' => new external_value(PARAM_INT, '0-based coding sequence index'),
        ]);
    }

    /**
     * Return the requested coding sequence's renderable data (no answers).
     *
     * @param int $cmid Course module id.
     * @param int $index 0-based sequence index.
     * @return array {sequence: JSON of {title, code, options}}
     */
    public static function execute(int $cmid, int $index): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'index' => $index]);
        $cm = self::setup_play_context($params['cmid']);

        $sequence = content::coding_sequence_public($cm->instance, $params['index']);
        if ($sequence === null) {
            throw new \moodle_exception('invalidsequence', 'mod_agon');
        }
        return ['sequence' => json_encode($sequence)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sequence' => new external_value(PARAM_RAW, 'JSON of the coding sequence: {title, code, options}'),
        ]);
    }
}
