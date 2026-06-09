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
use mod_agon\local\attempt;

/**
 * Web service: grade one game's submission server-side.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_game extends external_api {
    use returns_attempt_summary;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the agon activity'),
            'game' => new external_value(PARAM_ALPHA, 'Game key: crossword, question or coding'),
            'payload' => new external_value(PARAM_RAW, 'JSON-encoded student input for the game (no answers)'),
        ]);
    }

    /**
     * Grade the submission and return the updated attempt summary.
     *
     * @param int $cmid Course module id.
     * @param string $game Game key.
     * @param string $payload JSON-encoded student input.
     * @return array Attempt summary.
     */
    public static function execute(int $cmid, string $game, string $payload): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'game' => $game, 'payload' => $payload]);
        $cm = self::setup_play_context($params['cmid']);

        $input = json_decode($params['payload'], true);
        if (!is_array($input)) {
            $input = [];
        }

        $attempt = attempt::start($cm->instance, $USER->id);
        attempt::submit_game($attempt, $params['game'], $input);
        return attempt::summary(attempt::get($attempt->id));
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::attempt_summary_structure();
    }
}
