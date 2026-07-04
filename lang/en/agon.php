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
 * Plugin strings are defined here.
 *
 * @package     mod_agon
 * @category    string
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['agon:addinstance'] = 'Add a new Agon activity';
$string['agon:manage'] = 'Configure and monitor Agon activities';
$string['agon:play'] = 'Play Agon games';
$string['agon:viewleaderboard'] = 'View the Agon leaderboard';
$string['agonfieldset'] = 'General settings';
$string['agonname'] = 'Agon name';
$string['agonname_help'] = 'This is the name of this Agon activity as it appears on the course page.';
$string['agonsettings'] = 'Settings';
$string['attemptincomplete'] = 'You must finish every game before the run can be completed.';
$string['attemptnotinprogress'] = 'This attempt is not in progress, so it cannot be changed.';
$string['gamealreadysubmitted'] = 'This game has already been submitted in this attempt.';
$string['hintalreadyused'] = 'You have already used your hint for this game.';
$string['invalidgamejson'] = 'This must be valid JSON containing a non-empty "{$a}" list.';
$string['missingidandcmid'] = 'You must specify a course_module ID or an instance ID.';
$string['notconfigured'] = 'This activity has no playable game content yet — your teacher still needs to add it.';
$string['modulename'] = 'Agon';
$string['modulename_help'] = 'The Agon activity turns your course terms into competitive minigames — a crossword, a timed reveal-and-answer round, and a code-application challenge — so students revise by playing. Results feed a class leaderboard.';
$string['modulenameplural'] = 'Agons';
$string['noagoninstances'] = 'There are no Agon activities in this course.';
$string['pluginadministration'] = 'Agon administration';
$string['pluginname'] = 'Agon';
$string['privacy:metadata:agon_attempt'] = 'A student\'s attempt at an Agon activity: their scores, timing and progress.';
$string['privacy:metadata:agon_attempt:score'] = 'The total score for the attempt.';
$string['privacy:metadata:agon_attempt:scorecoding'] = 'The score for the coding game.';
$string['privacy:metadata:agon_attempt:scorecrossword'] = 'The score for the crossword game.';
$string['privacy:metadata:agon_attempt:scorequestion'] = 'The score for the weekly question game.';
$string['privacy:metadata:agon_attempt:state'] = 'Whether the attempt is in progress or finished.';
$string['privacy:metadata:agon_attempt:timefinish'] = 'The time the attempt was finished.';
$string['privacy:metadata:agon_attempt:timestart'] = 'The time the attempt was started.';
$string['privacy:metadata:agon_attempt:userid'] = 'The user who made the attempt.';
$string['questionbank'] = 'Question bank';
$string['questionbankintro'] = 'Add each game\'s content here. Choose which games the activity includes in the activity settings.';
$string['contentsaved'] = 'Saved.';
$string['aisettings'] = 'AI question generation';
$string['aienable'] = 'Enable AI generation';
$string['aienable_desc'] = 'Let teachers generate game content from lecture material using an AI provider. When off, only the "Copy prompt" helper is available.';
$string['aiprovider'] = 'Default provider';
$string['aiprovider_desc'] = 'The provider used when a teacher does not enter their own key.';
$string['aiapikey'] = 'Default Google API key';
$string['aiapikey_desc'] = 'Google AI Studio (Gemini) key used by default. For a file-based secret, set $CFG->forced_plugin_settings[\'mod_agon\'][\'aiapikey\'] in config.php instead.';
$string['aimodel'] = 'Default Google model';
$string['aimodel_desc'] = 'Model ID as shown in Google AI Studio, e.g. gemini-2.5-flash (others: gemini-2.0-flash, gemini-1.5-pro).';
$string['ainotconfigured'] = 'No default AI key is configured on this site. Pick a provider and enter your own key, or use "Copy prompt".';
$string['aidisabled'] = 'AI generation is turned off for this site. Use "Copy prompt" instead.';
$string['ainomaterial'] = 'Add lecture material first (paste text, read a link, or upload a file) — there is nothing to base the questions on.';
$string['aifetchfailed'] = 'Could not read that link. Make sure it is a Google Docs/Slides link shared as "anyone with the link", or paste the text instead.';
$string['aigenfailed'] = 'The AI request failed: {$a}';
$string['aibadjson'] = 'The AI did not return valid game JSON. Try again or edit it by hand.';
$string['aifileunsupported'] = 'Only PDF and PPTX files can be uploaded.';
$string['aiextractfailed'] = 'Could not read text from that file. For scanned PDFs, upload a PPTX or paste the text instead.';
$string['invalidsequence'] = 'That coding sequence does not exist.';
$string['testsettings'] = 'Testing';
$string['testmode'] = 'Testing mode (allow replays)';
$string['testmode_desc'] = 'When on, a "Play again" button appears on the results screen and lets any user reset their attempt and replay. For a test site only — leave off in a real course.';
$string['view'] = 'View';
