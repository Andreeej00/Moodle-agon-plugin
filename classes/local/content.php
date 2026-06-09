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
            'crossword' => ['words' => $cw['words'] ?? []],
            'questions' => $q['questions'] ?? [],
            'coding' => ['sequences' => $code['sequences'] ?? []],
        ];
    }

    /**
     * Renderable content with every answer removed — safe to send to the browser.
     *
     * Crossword words lose their letters (only number/clue/direction/row/col + length
     * remain); questions lose 'correct' and 'explanation'; coding sequences lose
     * 'blanks' and 'explanation'.
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

        $sequences = [];
        foreach ($raw['coding']['sequences'] as $s) {
            $sequences[] = [
                'title' => $s['title'] ?? '',
                'code' => $s['code'] ?? '',
                'options' => array_values($s['options'] ?? []),
            ];
        }

        return [
            'meta' => $raw['meta'],
            'crossword' => ['words' => $words],
            'questions' => $questions,
            'coding' => ['sequences' => $sequences],
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
                $sequences = [];
                foreach ($raw['coding']['sequences'] as $s) {
                    $sequences[] = [
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
     * One hint for a game: a crossword letter, the question explanation, or the next
     * code blank. Honours the student's current progress (passed in $payload) so a
     * crossword/coding hint targets a cell/blank they have not filled.
     *
     * @param int $agonid
     * @param string $game crossword|question|coding
     * @param array $payload Optional {filled: [keys]} of cells/blanks already filled.
     * @return array The hint (its shape depends on the game).
     */
    public static function hint(int $agonid, string $game, array $payload = []): array {
        $raw = self::raw($agonid);
        $filled = (array)($payload['filled'] ?? []);

        switch ($game) {
            case 'crossword':
                $solution = scoring::crossword_solution($raw['crossword']['words']);
                $candidates = array_diff(array_keys($solution), $filled);
                if (empty($candidates)) {
                    return ['type' => 'crossword'];
                }
                $key = $candidates[array_rand($candidates)];
                return ['type' => 'crossword', 'rc' => $key, 'letter' => $solution[$key]];
            case 'question':
                $q = $raw['questions'][0] ?? [];
                return ['type' => 'question', 'explanation' => $q['explanation'] ?? ''];
            case 'coding':
                foreach ($raw['coding']['sequences'] as $si => $s) {
                    foreach (array_values($s['blanks'] ?? []) as $b => $value) {
                        if (!in_array($si . '-' . $b, $filled, true)) {
                            return ['type' => 'coding', 'seq' => $si, 'b' => $b, 'value' => $value];
                        }
                    }
                }
                return ['type' => 'coding'];
            default:
                return [];
        }
    }
}
