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
 * AI-assisted content generation for the Question bank.
 *
 * Builds a game-specific prompt (used both by the "copy into any AI" helper and
 * by direct generation), fetches text from a Google Docs/Slides link, and calls
 * one of three providers (Google Gemini by default, or Claude / ChatGPT with the
 * teacher's own key). Keys are used server-side only and never returned.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai {
    /** @var string[] Providers we support. */
    const PROVIDERS = ['google', 'anthropic', 'openai'];
    /** @var int Cap on how much source material we forward to the model. */
    const SOURCE_MAX = 12000;

    /**
     * Build the generation prompt for a game, grounded in the source material.
     *
     * @param string $game crossword|question|coding
     * @param string $sourcetext Lecture material (pasted or fetched).
     * @param int $count How many items to generate (0 = the game default).
     * @param int $subcount Secondary per-game count: coding = lines per sequence, question = options per question (0 = default).
     * @return string The full prompt.
     */
    public static function prompt(string $game, string $sourcetext, int $count = 0, int $subcount = 0): string {
        switch ($game) {
            case 'crossword':
                $n = $count > 0 ? max(3, min(14, $count)) : 8;
                $task = "Build a CROSSWORD for revision. Produce exactly {$n} single-word answers "
                    . '(each ONE word, letters only, no spaces or hyphens) with a short clue each. '
                    . 'Do NOT lay out a grid — the app builds it. '
                    . 'Output ONLY minified JSON: '
                    . '{"grading":"custom","words":[{"word":"ANSWER","clue":"clue text"}]}. '
                    . 'Words must be UPPERCASE.';
                break;
            case 'question':
                $n = $count > 0 ? max(1, min(20, $count)) : 5;
                $o = $subcount > 0 ? max(3, min(6, $subcount)) : 4;
                $task = "Write exactly {$n} multiple-choice revision questions. Each has EXACTLY {$o} options and exactly one correct answer. "
                    . 'The "explanation" is ONE sentence giving the REASON the correct answer is right (the underlying concept, or why the others are wrong). '
                    . 'Do NOT merely restate the source — avoid empty phrasings such as "the text states…" or "the text defines it as…"; give the actual reason. '
                    . 'Output ONLY minified JSON: '
                    . '{"questions":[{"question":"...","options":["…"],"correct":0,"explanation":"..."}]} '
                    . "with exactly {$o} entries in \"options\". "
                    . '"correct" is the 0-based index of the right option.';
                break;
            case 'coding':
                $n = $count > 0 ? max(1, min(6, $count)) : 2;
                $s = $subcount > 0 ? max(1, min(5, $subcount)) : 1;
                $lines = $s === 1 ? 'exactly 1 line' : "exactly {$s} lines separated by newlines";
                $task = "Create exactly {$n} short fill-in-the-blank code sequences. "
                    . "Each sequence's \"code\" MUST have {$lines}, and EACH line MUST contain at least one ____ (four underscores) blank. "
                    . '"blanks" lists the correct fill for every ____ across all lines, in reading order (top line first). '
                    . '"options" lists every choice offered — the correct blanks plus plausible distractors (at least 5 options). '
                    . '"explanation" is ONE short sentence saying why those answers are the suitable choices. '
                    . 'Output ONLY minified JSON: '
                    . '{"sequences":[{"title":"...","code":"line1 ____\nline2 ____","blanks":["ans1","ans2"],'
                    . '"options":["ans1","ans2","distractor","..."],"explanation":"..."}]}.';
                break;
            default:
                $task = 'Output ONLY minified JSON.';
        }
        $source = trim($sourcetext);
        if ($source === '') {
            $source = '(No material supplied — use the general topic of the activity.)';
        } else if (\core_text::strlen($source) > self::SOURCE_MAX) {
            $source = \core_text::substr($source, 0, self::SOURCE_MAX);
        }
        return "You are helping a teacher build revision games in Moodle.\n" . $task . "\n"
            . "Base everything strictly on the SOURCE MATERIAL below. Return JSON only — no markdown, no commentary.\n\n"
            . "SOURCE MATERIAL:\n" . $source;
    }

    /**
     * Fetch and extract plain text from a Google Docs/Slides link.
     *
     * Only docs.google.com links are accepted, and only when the document is
     * shared as "anyone with the link" (otherwise Google returns a sign-in page).
     *
     * @param string $url
     * @return string Extracted text.
     * @throws \moodle_exception aifetchfailed when the link can't be read.
     */
    public static function fetch_source(string $url): string {
        global $CFG;

        $url = trim($url);
        if (strtolower((string)parse_url($url, PHP_URL_HOST)) !== 'docs.google.com'
                || !preg_match('#/(document|presentation)/d/([a-zA-Z0-9_\-]+)#', $url, $m)) {
            throw new \moodle_exception('aifetchfailed', 'mod_agon');
        }
        $export = $m[1] === 'document'
            ? 'https://docs.google.com/document/d/' . $m[2] . '/export?format=txt'
            : 'https://docs.google.com/presentation/d/' . $m[2] . '/export/txt';

        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $resp = $curl->get($export, [], ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_FOLLOWLOCATION' => 1, 'CURLOPT_MAXREDIRS' => 5]);
        $info = $curl->get_info();
        $code = $info['http_code'] ?? 0;
        if ($curl->errno || $code < 200 || $code >= 300) {
            throw new \moodle_exception('aifetchfailed', 'mod_agon');
        }
        $text = trim((string)$resp);
        // A non-shared document returns Google's HTML sign-in page instead of text.
        if ($text === '' || stripos($text, '<!doctype') === 0 || stripos($text, '<html') === 0) {
            throw new \moodle_exception('aifetchfailed', 'mod_agon');
        }
        return \core_text::substr($text, 0, 100000);
    }

    /**
     * Extract text from an uploaded lecture file (PDF or PPTX), self-contained.
     *
     * @param string $filename The original name (used for the extension).
     * @param string $base64 The file bytes, base64-encoded.
     * @return string Extracted text.
     * @throws \moodle_exception aifileunsupported|aiextractfailed
     */
    public static function extract_upload(string $filename, string $base64): string {
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            throw new \moodle_exception('aiextractfailed', 'mod_agon');
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'pptx') {
            return self::extract_pptx($bytes);
        }
        if ($ext === 'pdf') {
            return self::extract_pdf($bytes);
        }
        throw new \moodle_exception('aifileunsupported', 'mod_agon');
    }

    /**
     * Extract slide text from a .pptx (a ZIP of slideN.xml with <a:t> text runs).
     *
     * @param string $bytes
     * @return string
     * @throws \moodle_exception aiextractfailed
     */
    protected static function extract_pptx(string $bytes): string {
        $tmp = tempnam(sys_get_temp_dir(), 'agonpptx');
        file_put_contents($tmp, $bytes);
        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($tmp) === true) {
            $slides = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $slides[(int)$m[1]] = $name;
                }
            }
            ksort($slides);
            foreach ($slides as $name) {
                $xml = $zip->getFromName($name);
                if ($xml !== false && preg_match_all('#<a:t>(.*?)</a:t>#s', $xml, $mm)) {
                    foreach ($mm[1] as $t) {
                        $text .= html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8') . ' ';
                    }
                    $text .= "\n";
                }
            }
            $zip->close();
        }
        @unlink($tmp);
        $text = trim($text);
        if ($text === '') {
            throw new \moodle_exception('aiextractfailed', 'mod_agon');
        }
        return \core_text::substr($text, 0, 100000);
    }

    /**
     * Best-effort text extraction from a PDF (inflate FlateDecode streams, pull the
     * literal strings). Works for text-based PDFs; scanned/image PDFs yield nothing.
     *
     * @param string $bytes
     * @return string
     * @throws \moodle_exception aiextractfailed
     */
    protected static function extract_pdf(string $bytes): string {
        $text = '';
        // Capture up to the 'endstream' keyword (a stream's binary data can contain
        // any bytes, so we must not anchor on a trailing newline).
        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $bytes, $streams)) {
            foreach ($streams[1] as $chunk) {
                // An EOL may separate the data from 'endstream' — try with and without it.
                $candidates = [$chunk];
                $trimmed = preg_replace('/\r\n$|[\r\n]$/', '', $chunk);
                if ($trimmed !== $chunk) {
                    $candidates[] = $trimmed;
                }
                $data = false;
                foreach ($candidates as $cand) {
                    $d = @gzuncompress($cand);
                    if ($d === false) {
                        $d = @gzinflate($cand);
                    }
                    if ($d !== false) {
                        $data = $d;
                        break;
                    }
                }
                if ($data === false) {
                    $data = $chunk; // Possibly an uncompressed content stream.
                }
                $text .= self::pdf_strings($data) . "\n";
            }
        } else {
            $text = self::pdf_strings($bytes);
        }
        $text = trim(preg_replace('/[ \t]{2,}/', ' ', $text));
        if (\core_text::strlen($text) < 20) {
            throw new \moodle_exception('aiextractfailed', 'mod_agon');
        }
        return \core_text::substr($text, 0, 100000);
    }

    /**
     * Pull the literal (parenthesised) strings out of a PDF content stream.
     *
     * @param string $data
     * @return string
     */
    protected static function pdf_strings(string $data): string {
        $out = '';
        if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $data, $m)) {
            foreach ($m[0] as $s) {
                $s = substr($s, 1, -1);
                $s = strtr($s, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
                    '\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
                $out .= $s . ' ';
            }
        }
        return $out;
    }

    /**
     * Generate a game's content JSON from the source material via an AI provider.
     *
     * @param string $game crossword|question|coding
     * @param string $sourcetext Lecture material.
     * @param string $provider google|anthropic|openai
     * @param string $model Model id (empty = provider/site default).
     * @param string $apikey The teacher's own key (empty = use the site default key for the default provider).
     * @param int $count How many items to generate (0 = the game default).
     * @param int $subcount Secondary per-game count: coding = lines per sequence, question = options per question.
     * @return string The extracted JSON string (still to be validated by the caller).
     * @throws \moodle_exception aidisabled|ainotconfigured|aigenfailed
     */
    public static function generate(string $game, string $sourcetext, string $provider, string $model,
            string $apikey, int $count = 0, int $subcount = 0): string {
        if (!get_config('mod_agon', 'aienable')) {
            throw new \moodle_exception('aidisabled', 'mod_agon');
        }
        // No material → nothing to ground the questions on. Refuse rather than send a
        // generic prompt and get content unrelated to the lecture.
        if (trim($sourcetext) === '') {
            throw new \moodle_exception('ainomaterial', 'mod_agon');
        }
        $provider = in_array($provider, self::PROVIDERS, true) ? $provider : 'google';
        $key = self::resolve_key($provider, $apikey);
        if ($key === '') {
            throw new \moodle_exception('ainotconfigured', 'mod_agon');
        }
        $prompt = self::prompt($game, $sourcetext, $count, $subcount);
        switch ($provider) {
            case 'anthropic':
                $raw = self::call_anthropic($model, $key, $prompt);
                break;
            case 'openai':
                $raw = self::call_openai($model, $key, $prompt);
                break;
            default:
                $raw = self::call_google($model, $key, $prompt);
        }
        return self::extract_json($raw);
    }

    /**
     * Resolve the API key: the teacher's own key wins; otherwise the site default
     * key, but only for the site's default provider.
     *
     * @param string $provider
     * @param string $userkey
     * @return string
     */
    protected static function resolve_key(string $provider, string $userkey): string {
        $userkey = trim($userkey);
        if ($userkey !== '') {
            return $userkey;
        }
        $defaultprovider = trim((string)get_config('mod_agon', 'aiprovider')) ?: 'google';
        if ($provider === $defaultprovider) {
            return trim((string)get_config('mod_agon', 'aiapikey'));
        }
        return '';
    }

    /**
     * Call Google Gemini (generativelanguage API).
     *
     * @param string $model
     * @param string $key
     * @param string $prompt
     * @return string Model text.
     */
    protected static function call_google(string $model, string $key, string $prompt): string {
        $model = $model ?: (trim((string)get_config('mod_agon', 'aimodel')) ?: 'gemini-2.5-flash');
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
            . ':generateContent?key=' . rawurlencode($key);
        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.4, 'responseMimeType' => 'application/json'],
        ];
        $data = self::post_json($url, $body, []);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            throw new \moodle_exception('aigenfailed', 'mod_agon', '', self::api_error($data));
        }
        return $text;
    }

    /**
     * Call Anthropic Claude (messages API).
     *
     * @param string $model
     * @param string $key
     * @param string $prompt
     * @return string Model text.
     */
    protected static function call_anthropic(string $model, string $key, string $prompt): string {
        $model = $model ?: 'claude-sonnet-5';
        $body = ['model' => $model, 'max_tokens' => 4096, 'messages' => [['role' => 'user', 'content' => $prompt]]];
        $data = self::post_json('https://api.anthropic.com/v1/messages', $body,
            ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01']);
        $text = $data['content'][0]['text'] ?? '';
        if ($text === '') {
            throw new \moodle_exception('aigenfailed', 'mod_agon', '', self::api_error($data));
        }
        return $text;
    }

    /**
     * Call OpenAI (chat completions API).
     *
     * @param string $model
     * @param string $key
     * @param string $prompt
     * @return string Model text.
     */
    protected static function call_openai(string $model, string $key, string $prompt): string {
        $model = $model ?: 'gpt-4o';
        $body = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
        ];
        $data = self::post_json('https://api.openai.com/v1/chat/completions', $body,
            ['Authorization: Bearer ' . $key]);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text === '') {
            throw new \moodle_exception('aigenfailed', 'mod_agon', '', self::api_error($data));
        }
        return $text;
    }

    /**
     * POST JSON to a fixed provider endpoint and return the decoded response.
     *
     * @param string $url
     * @param array $body
     * @param string[] $headers Extra headers (auth); content-type is added here.
     * @return array Decoded JSON response.
     * @throws \moodle_exception aigenfailed on transport/HTTP error.
     */
    protected static function post_json(string $url, array $body, array $headers): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader('content-type: application/json');
        foreach ($headers as $h) {
            $curl->setHeader($h);
        }
        $resp = $curl->post($url, json_encode($body),
            ['CURLOPT_TIMEOUT' => 60, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $info = $curl->get_info();
        $code = $info['http_code'] ?? 0;
        if ($curl->errno) {
            throw new \moodle_exception('aigenfailed', 'mod_agon', '', $curl->error);
        }
        $data = json_decode((string)$resp, true);
        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception('aigenfailed', 'mod_agon', '',
                'HTTP ' . $code . ' ' . self::api_error(is_array($data) ? $data : ['raw' => $resp]));
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Pull a human-readable error out of a provider response.
     *
     * @param array $data
     * @return string
     */
    protected static function api_error(array $data): string {
        if (isset($data['error']['message'])) {
            return (string)$data['error']['message'];
        }
        if (isset($data['error']) && is_string($data['error'])) {
            return $data['error'];
        }
        return \core_text::substr(json_encode($data), 0, 300);
    }

    /**
     * Extract the JSON object from a model reply (strip code fences / prose).
     *
     * @param string $text
     * @return string
     */
    public static function extract_json(string $text): string {
        $t = trim($text);
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);
        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($t, $start, $end - $start + 1);
        }
        return $t;
    }
}
