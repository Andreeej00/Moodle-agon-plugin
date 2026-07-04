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
 * Web service: save one game's content JSON (Question bank authoring).
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_content extends external_api {
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
            'content' => new external_value(PARAM_RAW, 'JSON-encoded content for the game'),
        ]);
    }

    /**
     * Validate and store the game content.
     *
     * @param int $cmid Course module id.
     * @param string $game Game key.
     * @param string $content JSON-encoded content.
     * @return array {status: 'ok'}
     */
    public static function execute(int $cmid, string $game, string $content): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'game' => $game, 'content' => $content]);
        $cm = self::require_cm($params['cmid'], 'mod/agon:manage');

        if (!isset(content::COLUMNS[$params['game']])) {
            throw new \invalid_parameter_exception('Unknown game: ' . $params['game']);
        }
        content::save_game($cm->instance, $params['game'], $params['content']);

        return ['status' => 'ok'];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok on success'),
        ]);
    }
}
