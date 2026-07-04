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
 * Question bank: teacher authoring screen for mod_agon game content.
 *
 * Content used to live in the activity settings form; it now lives here, one
 * expandable panel per game, with manual + JSON input, a crossword builder and
 * AI-assisted generation. The settings form keeps only the game toggles.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('agon', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$moduleinstance = $DB->get_record('agon', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/agon:manage', $context);

$PAGE->set_url('/mod/agon/bank.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($moduleinstance->name) . ': ' . get_string('questionbank', 'mod_agon'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Decode the currently-saved content so the editors can prefill.
$decode = function ($json) {
    $value = json_decode((string)$json, true);
    return is_array($value) ? $value : [];
};

// Report only *whether* a default AI key exists — never the key itself. Teachers
// can override with their own key in the UI; the site key can also come from
// $CFG->forced_plugin_settings['mod_agon']['aiapikey'] (a config.php secret).
$defaultkey = trim((string)(get_config('mod_agon', 'aiapikey') ?: ''));
$aiconfig = [
    'enabled' => (bool)get_config('mod_agon', 'aienable'),
    'hasDefaultKey' => ($defaultkey !== ''),
    'defaultProvider' => get_config('mod_agon', 'aiprovider') ?: 'google',
    'defaultModel' => get_config('mod_agon', 'aimodel') ?: 'gemini-2.5-flash',
];

$bankdata = [
    'cmid' => (int)$cm->id,
    'games' => [
        'crossword' => (int)$moduleinstance->gamecrossword,
        'question' => (int)$moduleinstance->gamequestion,
        'coding' => (int)$moduleinstance->gamecoding,
    ],
    'crossword' => $decode($moduleinstance->contentcrossword),
    'question' => $decode($moduleinstance->contentquestion),
    'coding' => $decode($moduleinstance->contentcoding),
    'ai' => $aiconfig,
];

$PAGE->requires->js_call_amd('mod_agon/bank', 'init', [(int)$cm->id]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_agon/bank', [
    'name' => format_string($moduleinstance->name),
    'settingsurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false),
]);
echo html_writer::script('window.AGON_BANK = ' . json_encode($bankdata, JSON_HEX_TAG | JSON_HEX_AMP) . ';');
echo $OUTPUT->footer();
