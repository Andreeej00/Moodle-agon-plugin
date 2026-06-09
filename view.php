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
 * Prints an instance of mod_agon.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$a = optional_param('a', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('agon', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('agon', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('agon', ['id' => $a], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $moduleinstance->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('agon', $moduleinstance->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);

$modulecontext = context_module::instance($cm->id);

$event = \mod_agon\event\course_module_viewed::create([
    'objectid' => $moduleinstance->id,
    'context' => $modulecontext,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('agon', $moduleinstance);
$event->trigger();

$PAGE->set_url('/mod/agon/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($modulecontext);

// Teachers/assistants get the monitor view; students get the play view.
$canmanage = has_capability('moodle/course:manageactivities', $modulecontext);
$view = $canmanage ? 'professor' : 'student';

$enabledgames = [
    'crossword' => (bool)$moduleinstance->gamecrossword,
    'question' => (bool)$moduleinstance->gamequestion,
    'coding' => (bool)$moduleinstance->gamecoding,
];

// Placeholder leaderboard/attempts until cumulative tracking lands (Phase 2 step 5).
$placeholderlb = [
    ['name' => 'Amila H.', 'pts' => 14.25], ['name' => 'Tarik B.', 'pts' => 13.50], ['name' => 'Lejla K.', 'pts' => 12.75],
    ['name' => 'You', 'pts' => 11.50, 'me' => true], ['name' => 'Emir S.', 'pts' => 10.25], ['name' => 'Nina P.', 'pts' => 9.00],
];

// Answer-free content (subject/week + renderable game data with no answers).
$public = \mod_agon\local\content::public_for_render($moduleinstance->id);
$meta = $public['meta'];

if ($view === 'student') {
    $agondata = [
        'meta' => $meta,
        'enabledGames' => $enabledgames,
        'crossword' => $public['crossword'],
        'questions' => $public['questions'],
        'coding' => $public['coding'],
        'leaderboard' => $placeholderlb,
    ];
} else {
    $agondata = [
        'meta' => $meta,
        'enabledGames' => $enabledgames,
        'leaderboard' => $placeholderlb,
        'attempts' => [
            ['name' => 'Amila H.', 'crossword' => 1.0, 'question' => 1.0, 'coding' => 0.5, 'done' => true],
            ['name' => 'Tarik B.', 'crossword' => 0.75, 'question' => 1.0, 'coding' => 0.4, 'done' => true],
            ['name' => 'Lejla K.', 'crossword' => 0.75, 'question' => 0.0, 'coding' => 0.5, 'done' => true],
            ['name' => 'Emir S.', 'crossword' => 0.5, 'question' => 1.0, 'coding' => 0.3, 'done' => true],
            ['name' => 'Nina P.', 'crossword' => 0.5, 'question' => 0.0, 'coding' => 0.0, 'done' => false],
        ],
    ];
}

$week = $meta['week'] ?? '';
$topic = ($meta['topic'] ?? '') !== '' ? $meta['topic'] : format_string($moduleinstance->name);

// Students play via the AMD module (which calls the server web services);
// the teacher monitor still uses the plain professor.js (mock data, Phase 2 step 5).
if ($view === 'student') {
    $PAGE->requires->js_call_amd('mod_agon/player', 'init', [(int)$cm->id]);
} else {
    $jsfile = '/mod/agon/js/professor.js';
    $PAGE->requires->js(new moodle_url($jsfile, ['v' => filemtime($CFG->dirroot . $jsfile)]), false);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_agon/' . $view, [
    'name' => format_string($moduleinstance->name),
    'subject' => $topic,
    'week' => $week,
]);
echo html_writer::script('window.AGON = ' . json_encode($agondata, JSON_HEX_TAG | JSON_HEX_AMP) . ';');
echo $OUTPUT->footer();
