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
 * Tests for the AI content-generation helper (prompt building, source
 * extraction and the provider calls — the latter via mocked HTTP).
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\local\ai
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // ---------- prompt building ----------

    public function test_prompt_counts_and_clamps(): void {
        $p = ai::prompt('question', 'Material.', 5, 6);
        $this->assertStringContainsString('exactly 5 multiple-choice', $p);
        $this->assertStringContainsString('EXACTLY 6 options', $p);
        $this->assertStringContainsString('Material.', $p);

        // Counts clamp into each game's supported range.
        $this->assertStringContainsString('exactly 20 multiple-choice', ai::prompt('question', 'M', 100));
        $this->assertStringContainsString('exactly 3 single-word answers', ai::prompt('crossword', 'M', 1));
        $this->assertStringContainsString('exactly 14 single-word answers', ai::prompt('crossword', 'M', 99));
        $this->assertStringContainsString('exactly 6 short fill-in-the-blank', ai::prompt('coding', 'M', 42));
        // Coding subcount = lines per sequence (clamped to 5).
        $this->assertStringContainsString('exactly 5 lines', ai::prompt('coding', 'M', 2, 9));
        $this->assertStringContainsString('exactly 1 line', ai::prompt('coding', 'M', 2, 1));

        // Defaults when no count given.
        $this->assertStringContainsString('exactly 8 single-word answers', ai::prompt('crossword', 'M'));
        $this->assertStringContainsString('exactly 5 multiple-choice', ai::prompt('question', 'M'));
    }

    public function test_prompt_source_handling(): void {
        // Empty source gets the explicit "no material" note.
        $this->assertStringContainsString('(No material supplied', ai::prompt('question', "  \n "));

        // Long source is truncated to SOURCE_MAX characters.
        $long = str_repeat('a', ai::SOURCE_MAX) . 'OVERFLOWMARKER';
        $p = ai::prompt('question', $long);
        $this->assertStringNotContainsString('OVERFLOWMARKER', $p);

        // Unknown game still produces a JSON-only instruction.
        $this->assertStringContainsString('Output ONLY minified JSON', ai::prompt('poetry', 'M'));
    }

    // ---------- model-reply JSON extraction ----------

    public function test_extract_json(): void {
        $this->assertSame('{"a":1}', ai::extract_json('{"a":1}'));
        $this->assertSame('{"a":1}', ai::extract_json("```json\n{\"a\":1}\n```"));
        $this->assertSame('{"a":1}', ai::extract_json("Sure! Here you go:\n{\"a\":1}\nHope that helps."));
        // Outermost braces win, nested ones survive.
        $this->assertSame('{"a":{"b":2}}', ai::extract_json('x {"a":{"b":2}} y'));
        // Nothing brace-like: returned as-is (the caller's JSON validation rejects it).
        $this->assertSame('no json here', ai::extract_json('no json here'));
    }

    // ---------- generate() guards ----------

    public function test_generate_requires_the_feature_enabled(): void {
        set_config('aienable', 0, 'mod_agon');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/turned off/');
        ai::generate('question', 'Material', 'google', '', 'key');
    }

    public function test_generate_refuses_empty_material(): void {
        set_config('aienable', 1, 'mod_agon');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/lecture material/i');
        ai::generate('question', "   \n", 'google', '', 'key');
    }

    public function test_generate_needs_some_key(): void {
        set_config('aienable', 1, 'mod_agon');
        set_config('aiapikey', '', 'mod_agon');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/No default AI key/');
        ai::generate('question', 'Material', 'google', '', '');
    }

    public function test_generate_site_key_is_only_for_the_default_provider(): void {
        // A site Gemini key must not silently authorise a different provider.
        set_config('aienable', 1, 'mod_agon');
        set_config('aiprovider', 'google', 'mod_agon');
        set_config('aiapikey', 'site-key', 'mod_agon');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/No default AI key/');
        ai::generate('question', 'Material', 'anthropic', '', '');
    }

    // ---------- generate() provider calls (mocked HTTP) ----------

    public function test_generate_google_happy_path(): void {
        set_config('aienable', 1, 'mod_agon');
        set_config('aiprovider', 'google', 'mod_agon');
        set_config('aiapikey', 'site-key', 'mod_agon');
        \curl::mock_response(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => "```json\n{\"questions\":[]}\n```"]]]]],
        ]));
        $out = ai::generate('question', 'Material', 'google', '', '');
        $this->assertSame('{"questions":[]}', $out);
    }

    public function test_generate_anthropic_and_openai_with_own_key(): void {
        set_config('aienable', 1, 'mod_agon');
        \curl::mock_response(json_encode(['content' => [['text' => '{"words":[]}']]]));
        $this->assertSame('{"words":[]}', ai::generate('crossword', 'M', 'anthropic', '', 'teacher-key'));

        \curl::mock_response(json_encode(['choices' => [['message' => ['content' => '{"sequences":[]}']]]]));
        $this->assertSame('{"sequences":[]}', ai::generate('coding', 'M', 'openai', 'gpt-4o', 'teacher-key'));
    }

    public function test_generate_surfaces_provider_errors(): void {
        // A 200 response with no text (e.g. an error envelope) fails loudly,
        // carrying the provider's message.
        set_config('aienable', 1, 'mod_agon');
        \curl::mock_response(json_encode(['error' => ['message' => 'API key not valid']]));
        try {
            ai::generate('question', 'M', 'google', '', 'bad-key');
            $this->fail('Expected aigenfailed');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('API key not valid', $e->getMessage());
        }

        // An unrecognised provider falls back to google (never an unknown endpoint).
        \curl::mock_response(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '{"a":1}']]]]],
        ]));
        $this->assertSame('{"a":1}', ai::generate('question', 'M', 'doesnotexist', '', 'k'));
    }

    // ---------- fetch_source ----------

    public function test_fetch_source_rejects_non_google_links(): void {
        foreach (['https://example.com/document/d/abc123', 'not a url',
            'https://docs.google.com/spreadsheets/d/abc123'] as $bad) {
            try {
                ai::fetch_source($bad);
                $this->fail('Expected aifetchfailed for ' . $bad);
            } catch (\moodle_exception $e) {
                $this->assertSame('aifetchfailed', $e->errorcode);
            }
        }
    }

    public function test_fetch_source_reads_a_shared_doc(): void {
        \curl::mock_response("Lecture 4: tokenization and normalization.");
        $text = ai::fetch_source('https://docs.google.com/document/d/abc-123_XYZ/edit?usp=sharing');
        $this->assertSame('Lecture 4: tokenization and normalization.', $text);
    }

    public function test_fetch_source_detects_the_signin_page(): void {
        // A non-shared document returns Google's HTML sign-in page, not text.
        \curl::mock_response('<!DOCTYPE html><html><body>Sign in</body></html>');
        $this->expectException(\moodle_exception::class);
        ai::fetch_source('https://docs.google.com/presentation/d/abc123/edit');
    }

    // ---------- file extraction ----------

    /**
     * Build a minimal real .pptx (a ZIP with slide XML) and return its bytes.
     *
     * @param string[] $slides XML <a:t> texts, one per slide.
     * @return string
     */
    private function make_pptx(array $slides): string {
        $tmp = tempnam(sys_get_temp_dir(), 'agontest');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($slides as $i => $text) {
            $zip->addFromString('ppt/slides/slide' . ($i + 1) . '.xml',
                '<p:sld><a:t>' . $text . '</a:t><a:t> more</a:t></p:sld>');
        }
        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    public function test_extract_upload_pptx(): void {
        $bytes = $this->make_pptx(['Slide one &amp; intro', 'Slide two']);
        $text = ai::extract_upload('lecture.PPTX', base64_encode($bytes));
        $this->assertStringContainsString('Slide one & intro', $text);
        $this->assertStringContainsString('Slide two', $text);
        // Slides come out in order.
        $this->assertLessThan(strpos($text, 'Slide two'), strpos($text, 'Slide one'));
    }

    public function test_extract_upload_pdf(): void {
        // A text PDF stores page text as literal strings inside a FlateDecode
        // stream; gzcompress produces exactly that zlib stream format.
        $content = 'BT (Tokenization splits text) Tj (into a list of tokens.) Tj ET';
        $pdf = "%PDF-1.4\nstream\n" . gzcompress($content) . "\nendstream\n";
        $text = ai::extract_upload('lecture.pdf', base64_encode($pdf));
        $this->assertStringContainsString('Tokenization splits text', $text);
        $this->assertStringContainsString('into a list of tokens.', $text);
    }

    public function test_extract_upload_rejects_bad_input(): void {
        try {
            ai::extract_upload('notes.docx', base64_encode('bytes'));
            $this->fail('Expected aifileunsupported');
        } catch (\moodle_exception $e) {
            $this->assertSame('aifileunsupported', $e->errorcode);
        }
        try {
            ai::extract_upload('notes.pdf', '!!!not-base64!!!');
            $this->fail('Expected aiextractfailed');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiextractfailed', $e->errorcode);
        }
        try {
            // A PDF with no extractable text (e.g. a scan) fails with guidance.
            ai::extract_upload('scan.pdf', base64_encode('%PDF-1.4 no streams'));
            $this->fail('Expected aiextractfailed');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiextractfailed', $e->errorcode);
        }
    }
}
