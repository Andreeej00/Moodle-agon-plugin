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

// Fallback example content (used when a game is enabled but its box is empty or invalid).
$examplecw = ['week' => 5, 'topic' => 'Tokenization', 'words' => [
    ['number' => 1, 'word' => 'TOKEN', 'clue' => 'Smallest unit of text after splitting', 'direction' => 'across', 'row' => 0, 'col' => 0],
    ['number' => 2, 'word' => 'CORPUS', 'clue' => 'A collection of text documents', 'direction' => 'down', 'row' => 0, 'col' => 2],
    ['number' => 3, 'word' => 'LEMMA', 'clue' => 'Base form of a word after normalization', 'direction' => 'across', 'row' => 3, 'col' => 1],
]];
$exampleq = ['week' => 5, 'topic' => 'Tokenization', 'questions' => [
    ['question' => 'Which process splits text into its smallest units (tokens)?', 'options' => ['Tokenization', 'Lemmatization', 'Normalization', 'Vectorization'], 'correct' => 0, 'explanation' => 'Tokenization breaks text into tokens.'],
]];
$examplecode = ['sequences' => [
    ['title' => 'Sequence 1 — tokenization', 'code' => "text = \"Hello World\"\ntokens = text.____()\ntokens = [t.____() for t in tokens]\nprint(____(tokens))", 'blanks' => ['split', 'lower', 'len'], 'options' => ['split', 'lower', 'len', 'upper', 'join', 'strip'], 'explanation' => 'split() tokenizes, lower() normalizes case, len() counts.'],
    ['title' => 'Sequence 2 — filtering', 'code' => "words = [\"the\", \"cat\", \"sat\"]\nres = ____(filter(lambda w: len(w) > 2, words))\nprint(res[____])", 'blanks' => ['list', '0'], 'options' => ['list', 'upper', '0', 'set', 'title', '1'], 'explanation' => 'list() materializes the filter, res[0] takes the first.'],
]];

// Decode the saved JSON for each game; fall back to the example when empty or invalid.
$cw = json_decode((string)$moduleinstance->contentcrossword, true);
if (!is_array($cw) || empty($cw['words'])) {
    $cw = $examplecw;
}
$q = json_decode((string)$moduleinstance->contentquestion, true);
if (!is_array($q) || empty($q['questions'])) {
    $q = $exampleq;
}
$code = json_decode((string)$moduleinstance->contentcoding, true);
if (!is_array($code) || empty($code['sequences'])) {
    $code = $examplecode;
}

$week = $cw['week'] ?? ($q['week'] ?? '');
$topic = $cw['topic'] ?? ($q['topic'] ?? format_string($moduleinstance->name));

$agondata = [
    'meta' => ['subject' => $topic, 'week' => $week, 'topic' => $topic],
    'enabledGames' => [
        'crossword' => (bool)$moduleinstance->gamecrossword,
        'question' => (bool)$moduleinstance->gamequestion,
        'coding' => (bool)$moduleinstance->gamecoding,
    ],
    'crossword' => ['words' => $cw['words'] ?? []],
    'questions' => $q['questions'] ?? [],
    'coding' => ['sequences' => $code['sequences'] ?? []],
    // Leaderboard + attempts stay as placeholders until attempt tracking exists.
    'leaderboard' => [
        ['name' => 'Amila H.', 'pts' => 14.25], ['name' => 'Tarik B.', 'pts' => 13.50], ['name' => 'Lejla K.', 'pts' => 12.75],
        ['name' => 'You', 'pts' => 11.50, 'me' => true], ['name' => 'Emir S.', 'pts' => 10.25], ['name' => 'Nina P.', 'pts' => 9.00],
    ],
    'attempts' => [
        ['name' => 'Amila H.', 'crossword' => 1.0, 'question' => 1.0, 'coding' => 0.5, 'done' => true],
        ['name' => 'Tarik B.', 'crossword' => 0.75, 'question' => 1.0, 'coding' => 0.4, 'done' => true],
        ['name' => 'Lejla K.', 'crossword' => 0.75, 'question' => 0.0, 'coding' => 0.5, 'done' => true],
        ['name' => 'Emir S.', 'crossword' => 0.5, 'question' => 1.0, 'coding' => 0.3, 'done' => true],
        ['name' => 'Nina P.', 'crossword' => 0.5, 'question' => 0.0, 'coding' => 0.0, 'done' => false],
    ],
];

// Load in the footer (false), so it runs after the window.AGON data is printed below.
$PAGE->requires->js(new moodle_url('/mod/agon/js/' . $view . '.js', ['v' => get_config('mod_agon', 'version')]), false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_agon/' . $view, [
    'name' => format_string($moduleinstance->name),
    'subject' => $topic,
    'week' => $week,
]);
echo html_writer::script('window.AGON = ' . json_encode($agondata, JSON_HEX_TAG | JSON_HEX_AMP) . ';');
echo $OUTPUT->footer();
