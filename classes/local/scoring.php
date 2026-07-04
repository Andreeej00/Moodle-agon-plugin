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
 * Server-side scoring rules for the agon games (the "engine").
 *
 * Pure functions that encode the scoring rules from plan.md §4. They take the
 * saved game content (which holds the answers) plus the student's submission and
 * return a score. Nothing here touches the database or the request — the attempt
 * lifecycle layer feeds these and persists the result, so the browser never
 * decides a grade.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scoring {
    /** @var float Crossword points for finishers ranked 1st–3rd. */
    const CROSSWORD_TOP = 1.0;
    /** @var float Crossword points for finishers ranked 4th–10th. */
    const CROSSWORD_MID = 0.75;
    /** @var float Crossword points for every later finisher. */
    const CROSSWORD_REST = 0.5;
    /** @var float Highest a partial (not fully solved) crossword can score — always below CROSSWORD_REST. */
    const CROSSWORD_PARTIAL_MAX = 0.49;

    /**
     * Score the weekly question: 1.0 for the correct option, 0.0 otherwise.
     *
     * @param array $question One question, with an integer 'correct' option index.
     * @param int|null $selected The option index the student chose (null = no answer).
     * @return float 1.0 or 0.0.
     */
    public static function score_question(array $question, ?int $selected): float {
        if (!isset($question['correct']) || $selected === null) {
            return 0.0;
        }
        return ((int)$selected === (int)$question['correct']) ? 1.0 : 0.0;
    }

    /**
     * Score the coding game: the sequences share 1.0 evenly (0.5 each for the
     * standard two), with partial credit per correctly placed blank.
     *
     * @param array $sequences Each with a 'blanks' list of correct values in order.
     * @param array $answers Per-sequence list of placed values, indexed by sequence then blank.
     * @return float 0.0–1.0.
     */
    public static function score_coding(array $sequences, array $answers): float {
        $n = count($sequences);
        if ($n === 0) {
            return 0.0;
        }
        $perseq = 1.0 / $n;
        $total = 0.0;
        foreach (array_values($sequences) as $i => $seq) {
            $blanks = $seq['blanks'] ?? [];
            $count = count($blanks);
            if ($count === 0) {
                continue;
            }
            $given = $answers[$i] ?? [];
            $correct = 0;
            foreach (array_values($blanks) as $b => $want) {
                $got = $given[$b] ?? null;
                if ($got !== null && (string)$got === (string)$want) {
                    $correct++;
                }
            }
            $total += $perseq * ($correct / $count);
        }
        return $total;
    }

    /**
     * Build the crossword solution as a map of "row-col" => uppercase letter.
     *
     * @param array $words Each with word, direction (across|down), row, col.
     * @return array<string,string> Cell key to expected letter.
     */
    public static function crossword_solution(array $words): array {
        $cells = [];
        foreach ($words as $w) {
            $word = (string)($w['word'] ?? '');
            $row = (int)($w['row'] ?? 0);
            $col = (int)($w['col'] ?? 0);
            $across = ($w['direction'] ?? 'across') === 'across';
            $len = function_exists('mb_strlen') ? mb_strlen($word) : strlen($word);
            for ($i = 0; $i < $len; $i++) {
                $r = $across ? $row : $row + $i;
                $c = $across ? $col + $i : $col;
                $letter = function_exists('mb_substr') ? mb_substr($word, $i, 1) : substr($word, $i, 1);
                $cells[$r . '-' . $c] = strtoupper($letter);
            }
        }
        return $cells;
    }

    /**
     * Fraction (0.0–1.0) of crossword cells the student filled correctly.
     *
     * @param array $words The crossword words (the solution source).
     * @param array $entries Map of "row-col" => the letter the student typed.
     * @return float 0.0–1.0.
     */
    public static function crossword_correct_fraction(array $words, array $entries): float {
        $solution = self::crossword_solution($words);
        if (empty($solution)) {
            return 0.0;
        }
        $correct = 0;
        foreach ($solution as $rc => $want) {
            if (strtoupper((string)($entries[$rc] ?? '')) === $want) {
                $correct++;
            }
        }
        return $correct / count($solution);
    }

    /**
     * Fraction (0.0–1.0) of crossword WORDS the student got fully correct.
     *
     * A word counts only when every one of its cells matches. Used by the
     * "regular" grading mode, where the score is simply this fraction of a point
     * (e.g. 4 of 10 words right = 0.40).
     *
     * @param array $words The crossword words (each with word/direction/row/col).
     * @param array $entries Map of "row-col" => the letter the student typed.
     * @return float 0.0–1.0.
     */
    public static function crossword_correct_words_fraction(array $words, array $entries): float {
        $words = array_values($words);
        $total = count($words);
        if ($total === 0) {
            return 0.0;
        }
        $correctwords = 0;
        foreach ($words as $w) {
            $word = (string)($w['word'] ?? '');
            $row = (int)($w['row'] ?? 0);
            $col = (int)($w['col'] ?? 0);
            $across = ($w['direction'] ?? 'across') === 'across';
            $len = function_exists('mb_strlen') ? mb_strlen($word) : strlen($word);
            if ($len === 0) {
                continue;
            }
            $ok = true;
            for ($i = 0; $i < $len; $i++) {
                $r = $across ? $row : $row + $i;
                $c = $across ? $col + $i : $col;
                $want = function_exists('mb_substr') ? mb_substr($word, $i, 1) : substr($word, $i, 1);
                if (strtoupper((string)($entries[$r . '-' . $c] ?? '')) !== strtoupper($want)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $correctwords++;
            }
        }
        return $correctwords / $total;
    }

    /**
     * Whether the student fully solved the crossword (every cell correct).
     *
     * @param array $words The crossword words.
     * @param array $entries Map of "row-col" => the letter the student typed.
     * @return bool
     */
    public static function crossword_solved(array $words, array $entries): bool {
        return self::crossword_correct_fraction($words, $entries) >= 1.0;
    }

    /**
     * Final crossword score combining the finish-rank and partial-credit rules.
     *
     * A fully solved grid claims a finish-rank place among full solvers only
     * (1.0 / 0.75 / 0.5). A partial grid scores fraction × 0.5, capped at 0.49 so
     * it can never reach — let alone beat — a full solver's 0.5 floor.
     *
     * @param float $fraction Fraction (0.0–1.0) of cells the student got right.
     * @param int $priorsolvers How many students fully solved this crossword before (0-based).
     * @return float
     */
    public static function score_crossword(float $fraction, int $priorsolvers): float {
        if ($fraction >= 1.0) {
            return self::crossword_rank_points($priorsolvers);
        }
        return min($fraction * self::CROSSWORD_REST, self::CROSSWORD_PARTIAL_MAX);
    }

    /**
     * "Regular" crossword score: the fraction of fully-correct words as a point
     * out of 1.0 — a full solve = 1.0, with no finish-rank bonus and no partial cap.
     *
     * @param float $wordfraction Fraction (0.0–1.0) of words fully correct.
     * @return float 0.0–1.0.
     */
    public static function score_crossword_regular(float $wordfraction): float {
        return max(0.0, min(1.0, $wordfraction));
    }

    /**
     * Crossword finish-rank points: 1st–3rd = 1.0, 4th–10th = 0.75, rest = 0.5.
     *
     * @param int $priorfinishers How many students finished the crossword before this one (0-based).
     * @return float
     */
    public static function crossword_rank_points(int $priorfinishers): float {
        if ($priorfinishers < 3) {
            return self::CROSSWORD_TOP;
        }
        if ($priorfinishers < 10) {
            return self::CROSSWORD_MID;
        }
        return self::CROSSWORD_REST;
    }
}
