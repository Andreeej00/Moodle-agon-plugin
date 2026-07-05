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

namespace mod_agon\local;

use stdClass;

/**
 * Attempt lifecycle for the agon games.
 *
 * One attempt per student per activity instance. This layer owns the database
 * row, loads the saved (answer-bearing) content, runs the student's submission
 * through {@see scoring}, and persists the authoritative per-game and total
 * scores. The browser only ever sends the student's input — never a grade.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt {
    /** @var string The student is still playing. */
    const STATE_INPROGRESS = 'inprogress';
    /** @var string The run is over; scores are final. */
    const STATE_FINISHED = 'finished';
    /** @var string[] The games an attempt may submit. */
    const GAMES = ['crossword', 'question', 'coding'];

    /**
     * Return the student's attempt for this instance, creating it on first call.
     *
     * @param int $agonid The agon instance id.
     * @param int $userid The student.
     * @return stdClass The agon_attempt row.
     */
    public static function start(int $agonid, int $userid): stdClass {
        global $DB;

        $existing = $DB->get_record('agon_attempt', ['agonid' => $agonid, 'userid' => $userid]);
        if ($existing) {
            return $existing;
        }

        $now = time();
        $record = (object)[
            'agonid' => $agonid,
            'userid' => $userid,
            'state' => self::STATE_INPROGRESS,
            'timestart' => $now,
            'timefinish' => 0,
            'score' => 0,
            'scorecrossword' => 0,
            'scorequestion' => 0,
            'scorecoding' => 0,
            'timemodified' => $now,
            'submittedgames' => '[]',
            'hintsused' => '[]',
        ];
        $record->id = $DB->insert_record('agon_attempt', $record);
        return $record;
    }

    /**
     * Reload an attempt row by id.
     *
     * @param int $attemptid
     * @return stdClass
     */
    public static function get(int $attemptid): stdClass {
        global $DB;
        return $DB->get_record('agon_attempt', ['id' => $attemptid], '*', MUST_EXIST);
    }

    /**
     * Grade one game's submission server-side and persist the score.
     *
     * @param stdClass $attempt The attempt (only its id is trusted; the row is reloaded).
     * @param string $game One of crossword|question|coding.
     * @param array $payload The student's input for that game (no answers).
     * @return array{score: float, feedback: array} The recorded score and the reveal-on-submit feedback.
     */
    public static function submit_game(stdClass $attempt, string $game, array $payload): array {
        global $DB;

        if (!in_array($game, self::GAMES, true)) {
            throw new \coding_exception('Unknown agon game: ' . $game);
        }

        // Reload so totals are computed from the authoritative row, not a stale object.
        $attempt = self::get($attempt->id);
        if ($attempt->state !== self::STATE_INPROGRESS) {
            throw new \moodle_exception('attemptnotinprogress', 'mod_agon');
        }
        $submitted = self::submitted_games($attempt);
        if (in_array($game, $submitted, true)) {
            throw new \moodle_exception('gamealreadysubmitted', 'mod_agon');
        }

        $content = content::raw($attempt->agonid);

        switch ($game) {
            case 'crossword':
                $words = $content['crossword']['words'];
                $entries = (array)($payload['entries'] ?? []);
                if (($content['crossword']['grading'] ?? 'custom') === 'regular') {
                    // Regular: the score is just the fraction of fully-correct words (full solve = 1.0).
                    $score = scoring::score_crossword_regular(
                        scoring::crossword_correct_words_fraction($words, $entries)
                    );
                } else {
                    // Custom (default): a full solve claims a finish-rank place (1.0 / 0.75 / 0.5);
                    // a partial scores per-WORD fraction × 0.5, capped at 0.49 (a word counts only
                    // when every one of its cells is right — the same per-word rule as regular grading).
                    $fraction = scoring::crossword_correct_words_fraction($words, $entries);
                    $priorsolvers = $DB->count_records_select(
                        'agon_attempt',
                        'agonid = :agonid AND id <> :id AND scorecrossword >= :full',
                        ['agonid' => $attempt->agonid, 'id' => $attempt->id, 'full' => scoring::CROSSWORD_REST]
                    );
                    $score = scoring::score_crossword($fraction, $priorsolvers);
                }
                $field = 'scorecrossword';
                break;
            case 'question':
                $question = $content['questions'][0] ?? [];
                $selected = isset($payload['selected']) ? (int)$payload['selected'] : null;
                $score = scoring::score_question($question, $selected);
                $field = 'scorequestion';
                break;
            case 'coding':
            default:
                $sequences = $content['coding']['sequences'];
                $answers = (array)($payload['answers'] ?? []);
                $score = scoring::score_coding($sequences, $answers);
                $field = 'scorecoding';
                break;
        }

        $submitted[] = $game;
        $attempt->$field = $score;
        $attempt->score = (float)$attempt->scorecrossword
            + (float)$attempt->scorequestion
            + (float)$attempt->scorecoding;
        $attempt->submittedgames = json_encode(array_values($submitted));
        $attempt->timemodified = time();
        $DB->update_record('agon_attempt', $attempt);

        // Feedback from the content we already loaded (no extra read).
        return ['score' => $score, 'feedback' => content::feedback_from($content, $game)];
    }

    /**
     * Mark the attempt finished; scores become final.
     *
     * @param stdClass $attempt The attempt (reloaded internally).
     * @return stdClass The updated row.
     */
    public static function finish(stdClass $attempt): stdClass {
        global $DB;

        $attempt = self::get($attempt->id);
        if ($attempt->state === self::STATE_FINISHED) {
            return $attempt;
        }

        // Every playable game must have been submitted before the run can finish.
        $agon = $DB->get_record('agon', ['id' => $attempt->agonid], '*', MUST_EXIST);
        $playable = array_filter(content::playable_games($agon));
        $submitted = self::submitted_games($attempt);
        foreach (array_keys($playable) as $game) {
            if (!in_array($game, $submitted, true)) {
                throw new \moodle_exception('attemptincomplete', 'mod_agon');
            }
        }

        $attempt->state = self::STATE_FINISHED;
        $attempt->timefinish = time();
        $attempt->timemodified = $attempt->timefinish;
        $DB->update_record('agon_attempt', $attempt);

        return $attempt;
    }

    /**
     * The list of games already submitted in this attempt.
     *
     * @param stdClass $attempt
     * @return string[]
     */
    public static function submitted_games(stdClass $attempt): array {
        $list = json_decode((string)($attempt->submittedgames ?? ''), true);
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * The list of games whose one hint has already been used.
     *
     * @param stdClass $attempt
     * @return string[]
     */
    public static function hints_used(stdClass $attempt): array {
        $list = json_decode((string)($attempt->hintsused ?? ''), true);
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Spend this attempt's ONE hint (across the whole run) and return it.
     *
     * Enforced server-side: once any hint has been spent, every further hint is
     * refused — so a single hint can't be milked into an answer oracle.
     *
     * @param stdClass $attempt The attempt (reloaded internally).
     * @param string $game crossword|question|coding
     * @param array $payload Optional progress {filled: [...], seq: index} so the hint targets live cells/the open sequence.
     * @return array The hint.
     */
    public static function use_hint(stdClass $attempt, string $game, array $payload = []): array {
        global $DB;

        if (!in_array($game, self::GAMES, true)) {
            throw new \coding_exception('Unknown agon game: ' . $game);
        }

        $attempt = self::get($attempt->id);
        if ($attempt->state !== self::STATE_INPROGRESS) {
            throw new \moodle_exception('attemptnotinprogress', 'mod_agon');
        }
        $used = self::hints_used($attempt);
        // One hint per attempt: any prior hint (on any game) locks it out.
        if (!empty($used)) {
            throw new \moodle_exception('hintalreadyused', 'mod_agon');
        }

        $hint = content::hint($attempt->agonid, $game, $payload);

        // Only spend the hint if it actually revealed something: a crossword with
        // every cell already filled reveals no letters — so the student keeps it.
        $usable = !empty($hint['remove']) || !empty($hint['cells']) || !empty($hint['corrects']);
        if ($usable) {
            $used[] = $game;
            $attempt->hintsused = json_encode(array_values($used));
            $attempt->timemodified = time();
            $DB->update_record('agon_attempt', $attempt);
        }

        return $hint;
    }

    /**
     * Delete this student's attempt so they can play the activity again.
     *
     * Testing aid only — gated by the site 'testmode' setting at the call site
     * (view.php). Wipes the attempt row (and thus its leaderboard entry); the
     * gradebook grade is recomputed on the next finish.
     *
     * @param int $agonid
     * @param int $userid
     */
    public static function reset(int $agonid, int $userid): void {
        global $DB;
        $DB->delete_records('agon_attempt', ['agonid' => $agonid, 'userid' => $userid]);
    }

    /**
     * A web-service-friendly summary of an attempt (no answers).
     *
     * @param stdClass $attempt
     * @return array
     */
    public static function summary(stdClass $attempt): array {
        return [
            'attemptid' => (int)$attempt->id,
            'state' => $attempt->state,
            'timestart' => (int)$attempt->timestart,
            'timefinish' => (int)$attempt->timefinish,
            'scorecrossword' => (float)$attempt->scorecrossword,
            'scorequestion' => (float)$attempt->scorequestion,
            'scorecoding' => (float)$attempt->scorecoding,
            'score' => (float)$attempt->score,
            'submittedgames' => self::submitted_games($attempt),
            'hintsused' => self::hints_used($attempt),
        ];
    }
}
