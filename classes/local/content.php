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

/**
 * Game content access + the answer-split.
 *
 * One place that loads the saved per-game JSON and shapes it for three callers:
 * {@see raw()} (full content, answers included — for scoring), {@see public_for_render()}
 * (answers stripped — what the browser receives), and {@see feedback()} / {@see hint()}
 * (answers revealed deliberately, after a submission or as the one allowed hint).
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content {
    /**
     * Full decoded content for an instance, answers included. For server use only.
     *
     * @param int $agonid
     * @return array{crossword: array, questions: array, coding: array}
     */
    public static function raw(int $agonid): array {
        global $DB;

        $agon = $DB->get_record('agon', ['id' => $agonid], '*', MUST_EXIST);
        $decode = function ($json) {
            $value = json_decode((string)$json, true);
            return is_array($value) ? $value : [];
        };
        $cw = $decode($agon->contentcrossword);
        $q = $decode($agon->contentquestion);
        $code = $decode($agon->contentcoding);

        return [
            'meta' => [
                'week' => $cw['week'] ?? ($q['week'] ?? ($code['week'] ?? '')),
                'topic' => $cw['topic'] ?? ($q['topic'] ?? ''),
                'subject' => $cw['subject'] ?? ($cw['subject_code'] ?? ($q['subject_code'] ?? '')),
            ],
            'crossword' => [
                'words' => $cw['words'] ?? [],
                // 'custom' = finish-rank scoring (default); 'regular' = per-word fraction of a point.
                'grading' => (($cw['grading'] ?? 'custom') === 'regular') ? 'regular' : 'custom',
            ],
            'questions' => $q['questions'] ?? [],
            'coding' => ['sequences' => $code['sequences'] ?? []],
        ];
    }

    /**
     * Renderable content with every answer removed — safe to send to the browser.
     *
     * Crossword words lose their letters (only number/clue/direction/row/col + length
     * remain); questions lose 'correct' and 'explanation'; coding ships METADATA ONLY
     * (title + line count) — each sequence's code + options are lazy-loaded one at a
     * time via {@see coding_sequence_public()}, so the full set is never in the DOM.
     *
     * @param int $agonid
     * @return array{crossword: array, questions: array, coding: array}
     */
    public static function public_for_render(int $agonid): array {
        $raw = self::raw($agonid);

        $words = [];
        foreach ($raw['crossword']['words'] as $w) {
            $word = (string)($w['word'] ?? '');
            $words[] = [
                'number' => $w['number'] ?? null,
                'clue' => $w['clue'] ?? '',
                'direction' => $w['direction'] ?? 'across',
                'row' => (int)($w['row'] ?? 0),
                'col' => (int)($w['col'] ?? 0),
                'length' => function_exists('mb_strlen') ? mb_strlen($word) : strlen($word),
            ];
        }

        $questions = [];
        foreach ($raw['questions'] as $q) {
            $questions[] = [
                'question' => $q['question'] ?? '',
                'options' => array_values($q['options'] ?? []),
            ];
        }

        // Metadata only: the title + how many (non-empty) lines the sequence has, which
        // is all the player needs up front (timer budget + progress). Code/options come
        // per sequence from coding_sequence_public() as the student advances.
        $sequences = [];
        foreach ($raw['coding']['sequences'] as $s) {
            $code = (string)($s['code'] ?? '');
            $lines = 0;
            foreach (explode("\n", $code) as $line) {
                if (trim($line) !== '') {
                    $lines++;
                }
            }
            $sequences[] = ['title' => $s['title'] ?? '', 'lines' => max(1, $lines)];
        }

        return [
            'meta' => $raw['meta'],
            // 'grading' is not an answer (it only picks the scoring rule), so it is safe
            // to send: the student screen shows the matching scoring blurb + feedback.
            'crossword' => ['words' => $words, 'grading' => $raw['crossword']['grading'] ?? 'custom'],
            'questions' => $questions,
            'coding' => ['sequences' => $sequences],
        ];
    }

    /**
     * One coding sequence's renderable data (title + code + options, NO answers).
     *
     * The lazy-load endpoint: the player fetches sequences one at a time so the full
     * set of code/options is never present in the page at once.
     *
     * @param int $agonid
     * @param int $index 0-based sequence index.
     * @return array|null {title, code, options}, or null when the index is out of range.
     */
    public static function coding_sequence_public(int $agonid, int $index): ?array {
        $sequences = self::raw($agonid)['coding']['sequences'];
        if ($index < 0 || !isset($sequences[$index]) || !is_array($sequences[$index])) {
            return null;
        }
        $s = $sequences[$index];
        return [
            'title' => $s['title'] ?? '',
            'code' => $s['code'] ?? '',
            'options' => array_values($s['options'] ?? []),
        ];
    }

    /**
     * Subject/week metadata for an instance (no answers).
     *
     * @param int $agonid
     * @return array{week: mixed, topic: string, subject: string}
     */
    public static function meta(int $agonid): array {
        return self::raw($agonid)['meta'];
    }

    /**
     * Which games can actually be played: the toggle is on AND the saved JSON
     * holds real content. A game that is enabled but empty (or invalid JSON)
     * must not appear in the run, or students would play an empty screen and
     * bank a zero — and finish() would wait forever on it.
     *
     * @param \stdClass $agon The agon instance record (toggles + content columns).
     * @return array{crossword: bool, question: bool, coding: bool}
     */
    public static function playable_games(\stdClass $agon): array {
        $has = function ($json, $key) {
            $value = json_decode((string)$json, true);
            return is_array($value) && !empty($value[$key]) && is_array($value[$key]);
        };
        return [
            'crossword' => !empty($agon->gamecrossword) && $has($agon->contentcrossword, 'words'),
            'question' => !empty($agon->gamequestion) && $has($agon->contentquestion, 'questions'),
            'coding' => !empty($agon->gamecoding) && $has($agon->contentcoding, 'sequences'),
        ];
    }

    /** @var array<string,string> Game key => the agon table column that stores its JSON. */
    const COLUMNS = [
        'crossword' => 'contentcrossword',
        'question' => 'contentquestion',
        'coding' => 'contentcoding',
    ];

    /**
     * Validate + save one game's content JSON (the Question bank authoring path).
     *
     * @param int $agonid
     * @param string $game crossword|question|coding
     * @param string $json The content as a JSON string.
     * @throws \moodle_exception invalidgamejson when the JSON is malformed/incomplete.
     */
    public static function save_game(int $agonid, string $game, string $json): void {
        global $DB;

        if (!isset(self::COLUMNS[$game])) {
            throw new \coding_exception('Unknown agon game: ' . $game);
        }
        $decoded = json_decode($json, true);
        $missingkey = self::validate_game($game, $decoded);
        if ($missingkey !== null) {
            throw new \moodle_exception('invalidgamejson', 'mod_agon', '', $missingkey);
        }
        // Store canonical JSON, and touch the instance so caches/monitor stay fresh.
        $DB->set_field('agon', self::COLUMNS[$game], json_encode($decoded), ['id' => $agonid]);
        $DB->set_field('agon', 'timemodified', time(), ['id' => $agonid]);
    }

    /**
     * Check a decoded game payload has the shape the player + scoring need.
     *
     * @param string $game crossword|question|coding
     * @param mixed $decoded The json_decode(..., true) result.
     * @return string|null The required list name if invalid, or null when valid.
     */
    public static function validate_game(string $game, $decoded): ?string {
        if (!is_array($decoded)) {
            return self::COLUMNS[$game] ?? $game;
        }
        switch ($game) {
            case 'crossword':
                $words = $decoded['words'] ?? null;
                if (!is_array($words) || count($words) < 1) {
                    return 'words';
                }
                foreach ($words as $w) {
                    // Row/col must be non-negative ints: the player draws the grid from
                    // (0,0), so a negative coordinate would silently break rendering.
                    if (!is_array($w) || (string)($w['word'] ?? '') === ''
                            || !in_array($w['direction'] ?? '', ['across', 'down'], true)
                            || !isset($w['row']) || !isset($w['col'])
                            || (int)$w['row'] < 0 || (int)$w['col'] < 0) {
                        return 'words';
                    }
                }
                return null;
            case 'question':
                $questions = $decoded['questions'] ?? null;
                if (!is_array($questions) || count($questions) < 1) {
                    return 'questions';
                }
                foreach ($questions as $q) {
                    $options = $q['options'] ?? null;
                    if (!is_array($q) || (string)($q['question'] ?? '') === ''
                            || !is_array($options) || count($options) < 2 || !isset($q['correct'])) {
                        return 'questions';
                    }
                    // 'correct' must index an existing option, or the question is unanswerable.
                    $correct = (int)$q['correct'];
                    if ($correct < 0 || $correct >= count(array_values($options))) {
                        return 'questions';
                    }
                }
                return null;
            case 'coding':
                $sequences = $decoded['sequences'] ?? null;
                if (!is_array($sequences) || count($sequences) < 1) {
                    return 'sequences';
                }
                foreach ($sequences as $s) {
                    if (!is_array($s) || (string)($s['code'] ?? '') === ''
                            || !is_array($s['blanks'] ?? null) || !is_array($s['options'] ?? null)) {
                        return 'sequences';
                    }
                    // Every ____ needs exactly one answer, and every answer must be offered
                    // as an option — otherwise a blank can never be filled correctly.
                    $blanks = array_values($s['blanks']);
                    if (substr_count((string)$s['code'], '____') !== count($blanks) || count($blanks) < 1) {
                        return 'sequences';
                    }
                    $options = array_map('strval', array_values($s['options']));
                    foreach ($blanks as $b) {
                        if (!in_array((string)$b, $options, true)) {
                            return 'sequences';
                        }
                    }
                }
                return null;
            default:
                return $game;
        }
    }

    /**
     * Answers + explanation for a game, revealed after the student submits it.
     *
     * @param int $agonid
     * @param string $game crossword|question|coding
     * @return array
     */
    public static function feedback(int $agonid, string $game): array {
        return self::feedback_from(self::raw($agonid), $game);
    }

    /**
     * Answers + explanation for a game, from already-loaded raw content.
     *
     * Lets a caller that already holds the raw content (e.g. the scoring path)
     * build the reveal-on-submit feedback without re-reading the database.
     *
     * @param array $raw Output of {@see raw()}.
     * @param string $game crossword|question|coding
     * @return array
     */
    public static function feedback_from(array $raw, string $game): array {
        switch ($game) {
            case 'crossword':
                return ['solution' => scoring::crossword_solution($raw['crossword']['words'])];
            case 'question':
                $q = $raw['questions'][0] ?? [];
                return [
                    'correct' => isset($q['correct']) ? (int)$q['correct'] : null,
                    'explanation' => $q['explanation'] ?? '',
                ];
            case 'coding':
                // Include title + code here: after submit the review re-renders every
                // sequence, and by then it is lazy-loaded out of the page.
                $sequences = [];
                foreach ($raw['coding']['sequences'] as $s) {
                    $sequences[] = [
                        'title' => $s['title'] ?? '',
                        'code' => $s['code'] ?? '',
                        'blanks' => array_values($s['blanks'] ?? []),
                        'explanation' => $s['explanation'] ?? '',
                    ];
                }
                return ['sequences' => $sequences];
            default:
                return [];
        }
    }

    /**
     * The one hint for an attempt, shaped by the game it is spent on: crossword reveals
     * ceil(words/2) random unfilled letters, question marks the correct option, coding
     * marks the correct option(s) for the sequence in view. Honours the student's current
     * progress (passed in $payload) so the crossword hint targets cells they have not filled.
     *
     * @param int $agonid
     * @param string $game crossword|question|coding
     * @param array $payload Optional {filled: [keys], seq: index} — progress + open coding sequence.
     * @return array The hint (its shape depends on the game).
     */
    public static function hint(int $agonid, string $game, array $payload = []): array {
        $raw = self::raw($agonid);
        $filled = (array)($payload['filled'] ?? []);

        switch ($game) {
            case 'crossword':
                $words = $raw['crossword']['words'];
                $solution = scoring::crossword_solution($words);
                $candidates = array_values(array_diff(array_keys($solution), $filled));
                // Reveal half the puzzle (by word count), rounded up, at random cells.
                $reveal = (int)ceil(max(1, count($words)) / 2);
                shuffle($candidates);
                $cells = [];
                foreach (array_slice($candidates, 0, $reveal) as $rc) {
                    $cells[] = ['rc' => $rc, 'letter' => $solution[$rc]];
                }
                return ['type' => 'crossword', 'cells' => $cells];
            case 'question':
                // 50/50-style hint: eliminate floor(options/2) of the WRONG options.
                $q = $raw['questions'][0] ?? [];
                $options = array_values($q['options'] ?? []);
                $correct = isset($q['correct']) ? (int)$q['correct'] : -1;
                $wrong = [];
                foreach ($options as $i => $unused) {
                    if ($i !== $correct) {
                        $wrong[] = $i;
                    }
                }
                shuffle($wrong);
                $remove = array_slice($wrong, 0, (int)floor(count($options) / 2));
                sort($remove);
                return ['type' => 'question', 'remove' => array_values($remove)];
            case 'coding':
                $sequences = $raw['coding']['sequences'];
                $si = isset($payload['seq']) ? (int)$payload['seq'] : 0;
                if (!isset($sequences[$si])) {
                    $si = 0;
                }
                $seq = $sequences[$si] ?? [];
                $corrects = array_values(array_unique(array_map('strval', array_values($seq['blanks'] ?? []))));
                return ['type' => 'coding', 'seq' => $si, 'corrects' => $corrects];
            default:
                return [];
        }
    }
}
