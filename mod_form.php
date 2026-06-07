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
 * The main mod_agon configuration form.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Module instance settings form.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_agon_mod_form extends moodleform_mod {
    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // Adding the "general" fieldset, where all the common settings are shown.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('agonname', 'mod_agon'), ['size' => '64']);

        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }

        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'agonname', 'mod_agon');

        // Adding the standard "intro" and "introformat" fields.
        if ($CFG->branch >= 29) {
            $this->standard_intro_elements();
        } else {
            $this->add_intro_editor();
        }

        // --- Games in this activity ---
        $mform->addElement('header', 'agongames', 'Games in this activity');
        $mform->setExpanded('agongames', true);

        $mform->addElement('advcheckbox', 'gamecrossword', 'Crossword', 'Recall — fill a crossword grid from clues');
        $mform->setDefault('gamecrossword', 1);
        $mform->addElement('advcheckbox', 'gamequestion', 'Weekly question', 'Understand — timed multiple-choice question');
        $mform->setDefault('gamequestion', 1);
        $mform->addElement('advcheckbox', 'gamecoding', 'Coding', 'Apply — drag options into code blanks');
        $mform->setDefault('gamecoding', 1);

        // Editable example content for each selected game.
        $cwexample = '{
  "subject_code": "IT2012",
  "week": 5,
  "topic": "Tokenization",
  "words": [
    { "number": 1, "word": "TOKEN",  "clue": "Smallest unit of text after splitting", "direction": "across", "row": 0, "col": 0 },
    { "number": 2, "word": "CORPUS", "clue": "A collection of text documents",        "direction": "down",   "row": 0, "col": 2 }
  ]
}';
        $qexample = '{
  "subject_code": "IT2012",
  "week": 5,
  "topic": "Tokenization",
  "questions": [
    { "question": "Which process splits text into tokens?", "options": ["Tokenization", "Lemmatization", "Normalization", "Vectorization"], "correct": 0, "explanation": "Tokenization breaks text into tokens." }
  ]
}';
        $codeexample = '{
  "subject_code": "IT2012",
  "week": 5,
  "sequences": [
    { "title": "Tokenization", "code": "tokens = text.____()", "blanks": ["split"], "options": ["split", "lower", "len", "join", "strip"] }
  ]
}';

        // --- Crossword content ---
        $mform->addElement('static', 'cwguide', 'Crossword content',
            'Paste JSON: <code>{ subject_code, week, topic, words:[{ number, word, clue, direction: across|down, row, col }] }</code>. First 3 finishers score 1.0, 4th&ndash;10th 0.75, rest 0.5 (no timer).');
        $mform->addElement('textarea', 'contentcrossword', 'Crossword JSON', ['rows' => 12, 'cols' => 70]);
        $mform->setType('contentcrossword', PARAM_RAW);
        $mform->setDefault('contentcrossword', $cwexample);
        $mform->hideIf('cwguide', 'gamecrossword', 'notchecked');
        $mform->hideIf('contentcrossword', 'gamecrossword', 'notchecked');

        // --- Question content ---
        $mform->addElement('static', 'qguide', 'Question content',
            'Paste JSON: <code>{ subject_code, week, topic, questions:[{ question, options:[...], correct: index, explanation }] }</code> &mdash; ~5 per week. Timed; correct = 1.0.');
        $mform->addElement('textarea', 'contentquestion', 'Questions JSON', ['rows' => 12, 'cols' => 70]);
        $mform->setType('contentquestion', PARAM_RAW);
        $mform->setDefault('contentquestion', $qexample);
        $mform->hideIf('qguide', 'gamequestion', 'notchecked');
        $mform->hideIf('contentquestion', 'gamequestion', 'notchecked');

        // --- Coding content ---
        $mform->addElement('static', 'codeguide', 'Coding content',
            'Paste JSON: <code>{ subject_code, week, sequences:[{ title, code (use ____ for blanks), blanks:[...], options:[...] }] }</code> &mdash; 2 sequences, &ge;3 blanks, &ge;5 options. Timed; 0.5 each, partial.');
        $mform->addElement('textarea', 'contentcoding', 'Coding JSON', ['rows' => 12, 'cols' => 70]);
        $mform->setType('contentcoding', PARAM_RAW);
        $mform->setDefault('contentcoding', $codeexample);
        $mform->hideIf('codeguide', 'gamecoding', 'notchecked');
        $mform->hideIf('contentcoding', 'gamecoding', 'notchecked');

        // Add standard grading elements.
        $this->standard_grading_coursemodule_elements();

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();
    }
}
