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
use mod_agon\local\ai;

/**
 * Web service: build the AI prompt for a game (the copy-into-any-AI helper).
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_prompt extends external_api {
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
            'sourcetext' => new external_value(PARAM_RAW, 'Lecture material to ground the prompt', VALUE_DEFAULT, ''),
            'count' => new external_value(PARAM_INT, 'How many items to generate (0 = default)', VALUE_DEFAULT, 0),
            'subcount' => new external_value(PARAM_INT, 'Secondary count: coding lines per sequence / question options (0 = default)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return the prompt string.
     *
     * @param int $cmid Course module id.
     * @param string $game Game key.
     * @param string $sourcetext Source material.
     * @param int $count How many items to generate.
     * @param int $subcount Coding only: lines per sequence.
     * @return array {prompt: string}
     */
    public static function execute(int $cmid, string $game, string $sourcetext = '', int $count = 0,
            int $subcount = 0): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'game' => $game, 'sourcetext' => $sourcetext, 'count' => $count, 'subcount' => $subcount]);
        self::require_cm($params['cmid'], 'mod/agon:manage');

        return ['prompt' => ai::prompt($params['game'], $params['sourcetext'], $params['count'], $params['subcount'])];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'prompt' => new external_value(PARAM_RAW, 'The prompt to paste into an AI'),
        ]);
    }
}
