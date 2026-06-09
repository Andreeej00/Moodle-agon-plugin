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

if ($view === 'student') {
    // Answer-free content: subject/week + renderable game data with no answers.
    $public = \mod_agon\local\content::public_for_render($moduleinstance->id);
    $meta = $public['meta'];
    $agondata = [
        'meta' => $meta,
        'enabledGames' => $enabledgames,
        'crossword' => $public['crossword'],
        'questions' => $public['questions'],
        'coding' => $public['coding'],
        // The leaderboard is fetched live (mod_agon_get_leaderboard) once the run finishes.
    ];
} else {
    // The monitor only needs the subject/week heading, not the game data.
    $meta = \mod_agon\local\content::meta($moduleinstance->id);
    $agondata = [
        'meta' => $meta,
        'enabledGames' => $enabledgames,
        'leaderboard' => \mod_agon\local\leaderboard::course_totals($course->id),
        'attempts' => \mod_agon\local\leaderboard::attempts($moduleinstance->id),
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
