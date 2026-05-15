<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Document;
use App\Models\Chunk;

class AiController extends Controller
{
    private $apiBase = 'https://api.openai.com/v1';
    private $apiKey;

    public function __construct()
    {
        // Prefer OpenRouter if provided, otherwise fallback to OpenAI
        $openrouter = env('OPENROUTER_API_KEY');
        if (!empty($openrouter)) {
            $this->apiKey = $openrouter;
            $this->apiBase = 'https://openrouter.ai/api/v1';
        } else {
            $this->apiKey = env('OPENAI_API_KEY');
            $this->apiBase = 'https://api.openai.com/v1';
        }
    }

    public function upload(Request $request)
    {
        set_time_limit(300);
        $request->validate([
            'file' => 'required|file|mimes:pdf'
        ]);

        $file = $request->file('file');

        // store file locally
        $path = $file->store('uploads'); // storage/app/uploads

        $extracted = false;
        $textSnippet = null;
        $text = null;

        // If PDF parser is available, extract text and save a .txt copy
        if (class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile(Storage::path($path));
                $text = $pdf->getText();
                if (!empty(trim($text))) {
                    $txtPath = preg_replace('/\.pdf$/i', '.txt', $path);
                    Storage::put($txtPath, $text);
                    $extracted = true;
                    $textSnippet = mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 2000);
                }
            } catch (\Throwable $e) {
                // parser failed; ignore and continue
            }
        }

        if (!$extracted) {
            $fallbackText = $this->extractTextFromStoredFile($path);
            if (!empty($fallbackText)) {
                $text = $fallbackText;
                $extracted = true;
                $textSnippet = mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 2000);
            }
        }
        // Save a document record even if extraction fails so we can retry later.
        $documentRecord = null;
        try {
            DB::beginTransaction();
            $documentRecord = Document::create([
                'filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'text' => $extracted ? $text : null,
            ]);

            if ($extracted && !empty($text)) {
                $this->buildChunksForDocument($documentRecord, $text);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Could not save the uploaded PDF.',
                'detail' => $e->getMessage()
            ], 500);
        }

        return response()->json([
            'stored_path' => $path,
            'text_extracted' => $extracted,
            'text_snippet' => $textSnippet,
            'document_id' => $documentRecord ? $documentRecord->id : null,
            'warning' => $extracted ? null : 'PDF uploaded, but text extraction failed. The app will retry extraction when you ask a question.'
        ], 200);
    }

    public function ask(Request $request)
    {
        set_time_limit(300);
        $request->validate([
            'question' => 'required|string'
        ]);

        if (empty($this->apiKey)) {
            return response()->json(['error' => 'OPENAI_API_KEY not configured'], 500);
        }

        // If client provided explicit context text, use plain chat flow
        $contextText = $request->input('context');

        // Otherwise, perform retrieval from stored document chunks
        $documentId = $request->input('document_id');
        if (empty($contextText)) {
            if ($documentId) {
                $document = Document::find($documentId);
            } else {
                $document = Document::orderBy('id', 'desc')->first();
            }

            if (!$document) {
                return response()->json(['error' => 'No document found. Upload a PDF first or provide `context`.'], 422);
            }

            if ($document->chunks()->count() === 0 && !empty($document->text)) {
                $this->buildChunksForDocument($document, $document->text);
                $document->refresh();
            }

            if (empty($document->text) && !empty($document->file_path)) {
                $recoveredText = $this->extractTextFromStoredFile($document->file_path);
                if (!empty($recoveredText)) {
                    $document->text = $recoveredText;
                    $document->save();
                    if ($document->chunks()->count() === 0) {
                        $this->buildChunksForDocument($document, $recoveredText);
                        $document->refresh();
                    }
                }
            }

            $chunks = $document->chunks()->get()->toArray();
            if (empty($chunks)) {
                return response()->json(['error' => 'Document text could not be extracted. Please upload a text-based PDF or try a different file.'], 422);
            }

            $questionEmbedding = $this->getEmbedding($request->question);

            // compute similarities; fallback to keyword overlap if embeddings are missing
            $scores = [];
            $questionTokens = $this->tokenize($request->question);
            foreach ($chunks as $c) {
                $score = 0.0;
                if (isset($c['embedding']) && is_array($c['embedding']) && !empty($questionEmbedding)) {
                    $score = $this->cosineSim($questionEmbedding, $c['embedding']);
                } else {
                    $score = $this->keywordOverlapScore($questionTokens, $this->tokenize($c['chunk_text'] ?? ''));
                }
                $scores[] = ['chunk' => $c, 'score' => $score];
            }

            usort($scores, function ($a, $b) { return $b['score'] <=> $a['score']; });

            $top = array_slice($scores, 0, 5);
            $contextText = implode("\n\n", array_map(function ($s) { return $s['chunk']['chunk_text']; }, $top));
        }

        // Trim context to safe size
        $contextText = mb_substr($contextText, 0, 30000);

        $messages = [
            ['role' => 'system', 'content' => $this->lectureAiPrompt('question')],
            ['role' => 'user', 'content' => "Document:\n" . $contextText],
            ['role' => 'user', 'content' => "Question:\n" . $request->question]
        ];

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 800,
            'temperature' => 0.2
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json'
        ])->timeout(120)->post($this->apiBase . '/chat/completions', $payload);

        $content = $this->extractChatContent($response->json());

        return response()->json([
            'answer' => $content,
            'raw' => $response->json(),
        ], $response->status());
    }

    public function summarize(Request $request)
    {
        set_time_limit(300);

        if (empty($this->apiKey)) {
            return response()->json(['error' => 'OPENAI_API_KEY not configured'], 500);
        }

        $text = $request->input('text');
        $documentId = $request->input('document_id');

        $document = null;
        if (empty($text)) {
            if ($documentId) {
                $document = Document::find($documentId);
            } else {
                $document = Document::orderBy('id', 'desc')->first();
            }

            if ($document) {
                $text = $document->text;

                if (empty($text)) {
                    if (!empty($document->file_path)) {
                        $text = $this->extractTextFromStoredFile($document->file_path);
                        if (!empty($text)) {
                            $document->text = $text;
                            $document->save();
                        }
                    }

                    if (empty($text)) {
                    $chunks = $document->chunks()->orderBy('position')->get();
                    if ($chunks->isNotEmpty()) {
                        $text = $chunks->pluck('chunk_text')->implode("\n\n");
                    }
                    }
                }
            }
        }

        if (empty($text)) {
            return response()->json(['error' => 'No text to summarize. Upload a PDF first or wait for extraction to complete.'], 422);
        }

        // If the document is long, use a map-reduce summarization to avoid truncation
        $chunks = isset($chunks) ? $chunks : null;
        if ($chunks instanceof \Illuminate\Database\Eloquent\Collection) {
            $chunks = $chunks->pluck('chunk_text')->toArray();
        }

        // If we have chunked content available and it's sizable, perform chunk summarization
        if (!empty($chunks) && count($chunks) > 6) {
            $final = $this->summarizeChunks($chunks);
            return response()->json(['summary' => $final], 200);
        }

        // Fallback short summarization for smaller texts
        $text = mb_substr($text, 0, 30000);

        $messages = [
            ['role' => 'system', 'content' => $this->lectureAiPrompt('summary')],
            ['role' => 'user', 'content' => "Please provide a reviewer/summary structured exactly as requested, based on the following text:\n\n" . $text]
        ];

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 1200,
            'temperature' => 0.2
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json'
        ])->timeout(120)->post($this->apiBase . '/chat/completions', $payload);

        $content = $this->extractChatContent($response->json());

        return response()->json([
            'summary' => $content,
            'raw' => $response->json(),
        ], $response->status());
    }

    /**
     * Summarize many chunks using a map-reduce approach: summarize groups of chunks,
     * then combine the intermediate summaries and produce a final consolidated reviewer.
     */
    private function summarizeChunks(array $chunks): string
    {
        $groupSize = 6; // number of chunks per map call (larger groups reduce intermediate summaries)
        $groups = array_chunk($chunks, $groupSize);
        $intermediate = [];

        foreach ($groups as $i => $grp) {
            $segment = implode("\n\n", $grp);
            $messages = [
                ['role' => 'system', 'content' => $this->lectureAiPrompt('summary')],
                ['role' => 'user', 'content' => "Summarize the following document segment into a concise reviewer-style summary (keep headings and key takeaways). Output only the summary. Segment:\n\n" . mb_substr($segment, 0, 20000)]
            ];

            $payload = [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'max_tokens' => 600,
                'temperature' => 0.18
            ];

            try {
                $resp = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(120)->post($this->apiBase . '/chat/completions', $payload);

                $text = $this->extractChatContent($resp->json());
                if (!empty($text)) {
                    $intermediate[] = $text;
                }
            } catch (\Throwable $e) {
                // on error, store the raw segment truncated as a fallback
                $intermediate[] = mb_substr($segment, 0, 2000);
            }
        }

        // Combine intermediate summaries
        $combined = implode("\n\n", $intermediate);

        // Final synthesis step
        $finalMessages = [
            ['role' => 'system', 'content' => $this->lectureAiPrompt('summary')],
            ['role' => 'user', 'content' => "Combine the following intermediate summaries into one coherent reviewer/summary that follows the requested structure (headings, bold key terms, key takeaways, exam questions). Make it concise but comprehensive. Text:\n\n" . $combined]
        ];

        $finalPayload = [
            'model' => 'gpt-4o-mini',
            'messages' => $finalMessages,
            'max_tokens' => 1600,
            'temperature' => 0.18
        ];

        try {
            $finalResp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json'
            ])->timeout(120)->post($this->apiBase . '/chat/completions', $finalPayload);

            $finalText = $this->extractChatContent($finalResp->json());
            return $finalText ?? implode("\n\n", $intermediate);
        } catch (\Throwable $e) {
            return implode("\n\n", $intermediate);
        }
    }

    private function chunkText(string $text, int $wordsPerChunk = 500): array
    {
        $words = preg_split('/\s+/', trim($text));
        $chunks = [];
        $i = 0;
        while ($i < count($words)) {
            $chunks[] = implode(' ', array_slice($words, $i, $wordsPerChunk));
            $i += $wordsPerChunk;
        }
        return $chunks;
    }

    private function extractTextFromStoredFile(string $filePath): ?string
    {
        $fullPath = Storage::path($filePath);

        if (!file_exists($fullPath)) {
            return null;
        }

        try {
            if (class_exists('\\Smalot\\PdfParser\\Parser')) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($fullPath);
                $text = $pdf->getText();
                if (!empty(trim($text))) {
                    return $text;
                }
            }
        } catch (\Throwable $e) {
            // ignore and try Python fallback
        }

        $script = base_path('scripts/extract_pdf_text.py');
        if (!file_exists($script)) {
            return null;
        }

        $command = 'python ' . escapeshellarg($script) . ' ' . escapeshellarg($fullPath);
        $output = shell_exec($command);

        return !empty(trim((string)$output)) ? (string)$output : null;
    }

    private function getEmbedding(string $input)
    {
        if (empty($this->apiKey)) return null;

        try {
            $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json'
            ])->post($this->apiBase . '/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => $input
            ]);

            $json = $resp->json();
            if (isset($json['data'][0]['embedding'])) {
                return $json['data'][0]['embedding'];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    private function extractChatContent(array $payload): ?string
    {
        if (isset($payload['choices'][0]['message']['content'])) {
            return trim((string)$payload['choices'][0]['message']['content']);
        }

        if (isset($payload['choices'][0]['text'])) {
            return trim((string)$payload['choices'][0]['text']);
        }

        if (isset($payload['summary'])) {
            return trim((string)$payload['summary']);
        }

        return null;
    }

    private function lectureAiPrompt(string $mode): string
    {
        $base = <<<PROMPT
You are LectureAI, an intelligent academic assistant specializing in helping students understand lecture materials.

## Behavior Guidelines

### 1. Explaining Chapters
- Provide clear, structured explanations using bullet points and bold text for emphasis.

### 2. Generating Quizzes
When asked to generate a quiz:
- A "short quiz" means exactly 10 multiple-choice questions.
- Display ALL questions first. DO NOT put answers immediately after each question.
- Output each question using this format:
Q[Number]. [Question text]
A. [option]
B. [option]
C. [option]
D. [option]

- At the VERY END of the output, provide an "Answer Key" section structured like this:
**Answer Key & Explanations:**
1. [letter] - [why this is correct]
2. [letter] - [why this is correct]

### 3. Creating Reviewers
- Create a condensed summary (20-30% of original length).
- Use headings and subheadings.
- Highlight key terms in **bold**.
- Include a "Key Takeaways" section.

### 4. Auto Flashcards
When generating flashcards:
- Output strictly in format: [CONCEPT] || [DEFINITION/FACT]

### 5. Quiz Scoring
When scoring answers:
- Output: Score (X/Total) + Percentage.
- Show errors with correct answers and explanations.

## Response Format Rules
- Always prefix responses with relevant emoji (📘 for explanations, 📝 for quizzes/reviewers, 🃏 for flashcards, 📊 for scoring).
- If information is not in the provided document, say: "I don't see that in the uploaded materials. Could you clarify or upload a relevant section?"
- Your response must be based ONLY on the provided lecture context. Do not use external knowledge or hallucinate.
PROMPT;

        if ($mode === 'summary') {
            return $base . "\n\nWhen creating a reviewer/summary:\n- Create a condensed summary (20-30% of original length)\n- Use headings and subheadings\n- Highlight key terms in **bold**\n- Include a \"Key Takeaways\" section\n- Add a \"Common Exam Questions\" section\n- Prefix your response with 📝\n";
        }

        return $base;
    }

    private function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text);
        $parts = preg_split('/\s+/', trim($text));
        return array_values(array_filter($parts, function ($word) {
            return mb_strlen($word) > 2;
        }));
    }

    private function keywordOverlapScore(array $questionTokens, array $chunkTokens): float
    {
        if (empty($questionTokens) || empty($chunkTokens)) {
            return 0.0;
        }

        $questionSet = array_flip($questionTokens);
        $overlap = 0;
        foreach ($chunkTokens as $token) {
            if (isset($questionSet[$token])) {
                $overlap++;
            }
        }

        return $overlap / max(1, count($questionTokens));
    }

    private function buildChunksForDocument(Document $document, string $text): void
    {
        $chunks = $this->chunkText($text, 500);
        foreach ($chunks as $i => $chunkText) {
            $emb = null;
            try {
                $emb = $this->getEmbedding($chunkText);
            } catch (\Throwable $e) {
                $emb = null;
            }

            Chunk::create([
                'document_id' => $document->id,
                'position' => $i,
                'chunk_text' => $chunkText,
                'embedding' => $emb
            ]);
        }
    }

    private function cosineSim(array $a = null, array $b = null)
    {
        if (empty($a) || empty($b)) return 0.0;
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0 || $nb <= 0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
