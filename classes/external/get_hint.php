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
 * Web service: spend the attempt's one hint for a game.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_hint extends external_api {
    use uses_agon_context;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the agon activity'),
            'game' => new external_value(PARAM_ALPHA, 'Game key: crossword, question or coding'),
            'payload' => new external_value(PARAM_RAW, 'JSON of current progress, e.g. {filled:[...]}', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Return one hint for the game (server enforces one per game per attempt).
     *
     * @param int $cmid Course module id.
     * @param string $game Game key.
     * @param string $payload JSON progress.
     * @return array {hint: JSON}.
     */
    public static function execute(int $cmid, string $game, string $payload = '{}'): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'game' => $game, 'payload' => $payload]);
        $cm = self::setup_play_context($params['cmid']);

        $progress = json_decode($params['payload'], true);
        if (!is_array($progress)) {
            $progress = [];
        }

        $attempt = attempt::start($cm->instance, $USER->id);
        $hint = attempt::use_hint($attempt, $params['game'], $progress);
        return ['hint' => json_encode($hint)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hint' => new external_value(PARAM_RAW, 'JSON-encoded hint for the game'),
        ]);
    }
}
