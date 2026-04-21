<?php

namespace App\Console\Commands;

use App\Models\Script;
use App\Models\ScriptLog;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class RunScriptAutomationCommand extends Command
{
    protected $signature = 'automation:run-scripts {--limit=5 : Maximum items per status per run}';

    protected $description = 'Process scripts table: HeyGen generate, poll, then publish to Zrno.';

    public function handle(): int
    {
        $this->processPending();
        $this->processGenerating();
        $this->processPublishing();

        return self::SUCCESS;
    }

    private function processPending(): void
    {
        if ($this->hasActiveWorkInProgress()) {
            $this->info('Skipping pending queue: a script is still generating/publishing.');
            return;
        }

        Script::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(1)
            ->get()
            ->each(function (Script $script): void {
                try {
                    $this->writeLog($script, 'generate', 'info', 'Started HeyGen generation request.');
                    $payload = $this->buildHeyGenGeneratePayload($script);

                    $response = Http::timeout(60)
                        ->withHeaders([
                            'x-api-key' => (string) config('services.heygen.api_key'),
                            'Content-Type' => 'application/json',
                        ])
                        ->post('https://api.heygen.com/v2/video/generate', $payload);

                    if (! $response->successful()) {
                        $this->markError($script, 'HeyGen generate HTTP '.$response->status().': '.$response->body());
                        return;
                    }

                    $videoId = data_get($response->json(), 'data.video_id');

                    if (! $videoId) {
                        $this->markError($script, 'HeyGen generate: missing video_id in response.');
                        return;
                    }

                    $script->update([
                        'status' => 'generating',
                        'start_date' => $script->start_date ?? now(),
                        'finish_date' => null,
                        'heygen_session_id' => null,
                        'video_id' => (string) $videoId,
                        'video_url' => null,
                        'poll_attempts' => 0,
                        'error' => null,
                    ]);

                    $this->writeLog($script, 'generate', 'info', 'HeyGen generation accepted.', [
                        'video_id' => (string) $videoId,
                        'scene_count' => count($payload['video_inputs'] ?? []),
                        'caption' => (bool) ($payload['caption'] ?? false),
                        'scene_split' => (string) config('services.heygen.scene_split', 'auto'),
                    ]);
                } catch (Throwable $e) {
                    $this->markError($script, 'HeyGen generate exception: '.$e->getMessage());
                }
            });
    }

    private function hasActiveWorkInProgress(): bool
    {
        return Script::query()
            ->whereIn('status', ['generating', 'publishing'])
            ->exists();
    }

    private function processGenerating(): void
    {
        $limit = (int) $this->option('limit');
        $maxPollAttempts = (int) config('services.heygen.max_poll_attempts', 60);

        Script::query()
            ->where('status', 'generating')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Script $script) use ($maxPollAttempts): void {
                if (! $script->video_id) {
                    $this->markError($script, 'Missing video_id while generating.');
                    return;
                }

                if ($script->poll_attempts >= $maxPollAttempts) {
                    $this->markError($script, 'HeyGen polling timeout reached.');
                    return;
                }

                try {
                    $script->increment('poll_attempts');
                    $script->update([
                        'last_polled_at' => now(),
                    ]);

                    $response = Http::timeout(60)
                        ->withHeaders([
                            'x-api-key' => (string) config('services.heygen.api_key'),
                        ])
                        ->get('https://api.heygen.com/v1/video_status.get', [
                            'video_id' => $script->video_id,
                        ]);

                    if (! $response->successful()) {
                        $this->markError($script, 'HeyGen status HTTP '.$response->status().': '.$response->body());
                        return;
                    }

                    $status = data_get($response->json(), 'data.status');
                    $this->writeLog($script, 'poll', 'info', 'HeyGen status polled.', [
                        'video_id' => $script->video_id,
                        'poll_attempts' => $script->fresh()->poll_attempts,
                        'status' => $status,
                    ]);

                    if ($status === 'completed') {
                        $videoUrl = data_get($response->json(), 'data.video_url');

                        if (! $videoUrl) {
                            $this->markError($script, 'HeyGen completed but video_url is missing.');
                            return;
                        }

                        $script->update([
                            'status' => 'publishing',
                            'video_url' => $videoUrl,
                            'error' => null,
                        ]);
                        $this->writeLog($script, 'poll', 'info', 'HeyGen video completed.', [
                            'video_url' => $videoUrl,
                        ]);
                        return;
                    }

                    if ($status === 'failed') {
                        $failedReason = data_get($response->json(), 'data.error.message')
                            ?? data_get($response->json(), 'data.error.detail')
                            ?? 'HeyGen render failed.';
                        $this->markError($script, (string) $failedReason);
                        return;
                    }
                } catch (Throwable $e) {
                    $this->markError($script, 'HeyGen status exception: '.$e->getMessage());
                }
            });
    }

    private function processPublishing(): void
    {
        $limit = (int) $this->option('limit');

        Script::query()
            ->where('status', 'publishing')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Script $script): void {
                if (! $script->video_url) {
                    $this->markError($script, 'Missing video_url while publishing.');
                    return;
                }

                try {
                    $this->writeLog($script, 'publish', 'info', 'Started publish request.');
                    $platforms = $this->resolveZrnoPlatforms();

                    if ($platforms === []) {
                        $this->markError($script, 'Zrno platforms are not configured. Set ZRNO_PLATFORMS_JSON or ZRNO_PLATFORM + ZRNO_ACCOUNT_ID.');
                        return;
                    }

                    $selectedPlatform = $this->resolveNextPublishPlatform($platforms);
                    $this->writeLog($script, 'publish', 'info', 'Selected platform for this publish.', [
                        'platform' => $selectedPlatform['platform'],
                        'accountId' => $selectedPlatform['accountId'],
                    ]);

                    // Zerno `content`: caption (or script) + hashtags in the same string so Instagram shows them.
                    $caption = $this->buildPublishCaption($script);
                    $postPayload = [
                        'content' => $caption,
                        'mediaItems' => [[
                            'type' => 'video',
                            'url' => $script->video_url,
                        ]],
                        'platforms' => [$selectedPlatform],
                        'publishNow' => true,
                    ];

                    $response = Http::timeout(60)
                        ->withToken((string) config('services.zrno.api_key'))
                        ->post((string) config('services.zrno.base_url').'/v1/posts', $postPayload);

                    if ($response->successful()) {
                        $script->update([
                            'status' => 'done',
                            'finish_date' => now(),
                            'published_platform' => $selectedPlatform['platform'],
                            'publish_response' => $response->json(),
                            'error' => null,
                        ]);
                        $this->writeLog($script, 'publish', 'info', 'Publish completed successfully.', [
                            'platform' => $selectedPlatform['platform'],
                        ]);

                        return;
                    }

                    if ($this->isZrnoDuplicateContentResponse($response)) {
                        $this->markPublishSkippedDuplicate($script, $selectedPlatform, $response);

                        return;
                    }

                    $this->markError($script, 'Zrno publish HTTP '.$response->status().': '.$response->body());
                } catch (Throwable $e) {
                    $this->markError($script, 'Zrno publish exception: '.$e->getMessage());
                }
            });
    }

    /**
     * Text sent as Zerno `content`: `scripts.caption` when set, otherwise `scripts.script` (legacy rows).
     * Hashtags are appended to this string so they appear in the Instagram/TikTok caption (not only API metadata).
     * Optional ref suffix is off by default; enable only if you need duplicate-post avoidance on Zerno.
     *
     * @see config('services.zrno.append_unique_caption_suffix')
     */
    private function buildPublishCaption(Script $script): string
    {
        $caption = trim((string) ($script->caption ?? ''));
        $body = $caption !== '' ? $caption : trim((string) $script->script);

        $tags = $this->resolvePublishHashtags($script);
        if ($tags !== []) {
            $body .= "\n\n".implode(' ', $tags);
        }

        if ((bool) config('services.zrno.append_unique_caption_suffix', false)) {
            return $body.sprintf(
                "\n\n— Ref #%d · %s UTC",
                $script->id,
                now()->utc()->format('Y-m-d H:i')
            );
        }

        return $body;
    }

    /**
     * @return list<string>
     */
    private function resolvePublishHashtags(Script $script): array
    {
        $tags = $script->hashtags;
        if (! is_array($tags)) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            $t = trim((string) $tag);
            if ($t === '') {
                continue;
            }
            if (! str_starts_with($t, '#')) {
                $t = '#'.$t;
            }
            $out[$t] = true;
        }

        return array_keys($out);
    }

    private function isZrnoDuplicateContentResponse(Response $response): bool
    {
        if ($response->status() !== 409) {
            return false;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return false;
        }

        $error = data_get($json, 'error');
        if (! is_string($error)) {
            return false;
        }

        $lower = strtolower($error);

        return str_contains($lower, 'already scheduled')
            || str_contains($lower, 'exact content')
            || str_contains($lower, 'already posted');
    }

    /**
     * @param array{platform:string,accountId:string} $selectedPlatform
     */
    private function markPublishSkippedDuplicate(Script $script, array $selectedPlatform, Response $response): void
    {
        $json = $response->json();
        $script->update([
            'status' => 'done',
            'finish_date' => now(),
            'published_platform' => $selectedPlatform['platform'],
            'publish_response' => [
                'skipped_duplicate' => true,
                'zrno_http_status' => $response->status(),
                'zrno_body' => is_array($json) ? $json : ['raw' => $response->body()],
            ],
            'error' => null,
        ]);

        $this->writeLog($script, 'publish', 'info', 'Zrno duplicate guard: same caption already on this account. No new post created; script marked done.', [
            'platform' => $selectedPlatform['platform'],
            'existingPostId' => is_array($json) ? data_get($json, 'details.existingPostId') : null,
        ]);
    }

    private function markError(Script $script, string $message): void
    {
        $script->update([
            'status' => 'error',
            'start_date' => $script->start_date ?? now(),
            'finish_date' => now(),
            'error' => mb_substr($message, 0, 65535),
        ]);

        $this->writeLog($script, 'error', 'error', $message);
        $this->error("Script #{$script->id}: {$message}");
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function writeLog(Script $script, string $stage, string $level, string $message, ?array $context = null): void
    {
        ScriptLog::query()->create([
            'script_id' => $script->id,
            'stage' => $stage,
            'level' => $level,
            'message' => mb_substr($message, 0, 65535),
            'context' => $context,
        ]);
    }

    /**
     * Full HeyGen POST body for /v2/video/generate (multi-scene advice format + captions + optional size).
     *
     * @return array<string, mixed>
     */
    private function buildHeyGenGeneratePayload(Script $script): array
    {
        $videoInputs = $this->buildHeyGenVideoInputs($script);

        $payload = [
            'video_inputs' => $videoInputs,
            'title' => 'script-'.$script->id,
            'callback_id' => (string) $script->id,
        ];

        if ((bool) config('services.heygen.caption', true)) {
            $payload['caption'] = true;
        }

        if ((bool) config('services.heygen.open_caption', false)) {
            $payload['open_caption'] = true;
        }

        $dimensionWidth = (int) config('services.heygen.dimension_width', 1080);
        $dimensionHeight = (int) config('services.heygen.dimension_height', 1920);
        if ($dimensionWidth <= 0) {
            $dimensionWidth = 1080;
        }
        if ($dimensionHeight <= 0) {
            $dimensionHeight = 1920;
        }
        $payload['dimension'] = [
            'width' => $dimensionWidth,
            'height' => $dimensionHeight,
        ];

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHeyGenVideoInputs(Script $script): array
    {
        $raw = trim((string) $script->script);
        if ($raw === '') {
            return [[
                'character' => $this->heyGenCharacterBlock(),
                'voice' => $this->heyGenVoiceBlock('.'),
            ]];
        }

        $scenes = (bool) config('services.heygen.multi_scene', true)
            ? $this->splitScriptIntoScenes($raw)
            : [$this->trimScriptToTargetDuration($raw)];

        $scenes = array_values(array_filter($scenes, static fn (string $s): bool => trim($s) !== ''));

        if ($scenes === []) {
            $scenes = [$raw];
        }

        $maxWordsTotal = $this->maxWordsForVideoBudget();
        $sceneCount = max(1, count($scenes));
        $perSceneWordCap = max(1, (int) floor($maxWordsTotal / $sceneCount));

        $inputs = [];
        foreach ($scenes as $sceneText) {
            $line = $this->trimTextToWordLimit(trim($sceneText), $perSceneWordCap);
            if ($line === '') {
                continue;
            }
            $inputs[] = [
                'character' => $this->heyGenCharacterBlock(),
                'voice' => $this->heyGenVoiceBlock($line),
            ];
        }

        if ($inputs === []) {
            $inputs[] = [
                'character' => $this->heyGenCharacterBlock(),
                'voice' => $this->heyGenVoiceBlock($this->trimTextToWordLimit($raw, $maxWordsTotal)),
            ];
        }

        return $inputs;
    }

    /**
     * @return array<string, string>
     */
    private function heyGenCharacterBlock(): array
    {
        $character = [
            'type' => 'avatar',
            'avatar_id' => (string) config('services.heygen.avatar_id'),
        ];

        if (filled(config('services.heygen.orientation'))) {
            $character['orientation'] = (string) config('services.heygen.orientation');
        }

        return $character;
    }

    /**
     * @return array<string, string>
     */
    private function heyGenVoiceBlock(string $inputText): array
    {
        $voice = [
            'type' => 'text',
            'input_text' => $inputText,
        ];

        if (filled(config('services.heygen.voice_id'))) {
            $voice['voice_id'] = (string) config('services.heygen.voice_id');
        }

        return $voice;
    }

    private function maxWordsForVideoBudget(): int
    {
        $targetSeconds = (int) config('services.heygen.target_seconds', 20);
        $wpm = (int) config('services.heygen.words_per_minute', 150);

        return max(1, (int) floor(($targetSeconds / 60) * $wpm));
    }

    /**
     * Splits advice / self-improvement style scripts into HeyGen scenes: paragraphs first, else one line per beat
     * (fits short-form “wisdom” lines like your example).
     *
     * @return list<string>
     */
    private function splitScriptIntoScenes(string $script): array
    {
        $mode = strtolower(trim((string) config('services.heygen.scene_split', 'auto')));
        $maxScenes = max(1, min(50, (int) config('services.heygen.max_scenes', 12)));
        $normalized = trim((string) (preg_replace("/\r\n|\r/", "\n", $script) ?? $script));

        if ($mode === 'single') {
            return [$normalized];
        }

        if ($mode === 'paragraph') {
            $parts = preg_split('/\n\s*\n+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : [])));

            return $this->capSceneCount($parts !== [] ? $parts : [$normalized], $maxScenes);
        }

        if ($mode === 'line') {
            $lines = preg_split('/\n+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
            $lines = array_values(array_filter(array_map('trim', is_array($lines) ? $lines : [])));

            return $this->capSceneCount($lines !== [] ? $lines : [$normalized], $maxScenes);
        }

        // auto: paragraphs if 2+, else lines if 2+, else single scene
        $paragraphs = preg_split('/\n\s*\n+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $paragraphs = array_values(array_filter(array_map('trim', is_array($paragraphs) ? $paragraphs : [])));
        if (count($paragraphs) >= 2) {
            return $this->capSceneCount($paragraphs, $maxScenes);
        }

        $lines = preg_split('/\n+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $lines = array_values(array_filter(array_map('trim', is_array($lines) ? $lines : [])));
        if (count($lines) >= 2) {
            return $this->capSceneCount($lines, $maxScenes);
        }

        return [$normalized];
    }

    /**
     * @param list<string> $scenes
     * @return list<string>
     */
    private function capSceneCount(array $scenes, int $maxScenes): array
    {
        if (count($scenes) <= $maxScenes) {
            return $scenes;
        }

        $head = array_slice($scenes, 0, $maxScenes - 1);
        $tail = array_slice($scenes, $maxScenes - 1);
        $merged = trim(implode(' ', $tail));
        if ($merged !== '') {
            $head[] = $merged;
        }

        return $head;
    }

    private function trimTextToWordLimit(string $script, int $maxWords): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $script) ?? $script);
        $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($words) || count($words) <= $maxWords) {
            return $clean;
        }

        return implode(' ', array_slice($words, 0, $maxWords));
    }

    private function trimScriptToTargetDuration(string $script): string
    {
        return $this->trimTextToWordLimit($script, $this->maxWordsForVideoBudget());
    }

    /**
     * @return array<int, array{platform:string,accountId:string}>
     */
    private function resolveZrnoPlatforms(): array
    {
        $platformsJson = (string) config('services.zrno.platforms_json', '');

        if ($platformsJson !== '') {
            $decoded = json_decode($platformsJson, true);

            if (is_array($decoded)) {
                $platforms = [];

                foreach ($decoded as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $platform = isset($item['platform']) ? trim((string) $item['platform']) : '';
                    $accountId = isset($item['accountId']) ? trim((string) $item['accountId']) : '';

                    if ($platform !== '' && $accountId !== '') {
                        $platforms[] = [
                            'platform' => $platform,
                            'accountId' => $accountId,
                        ];
                    }
                }

                if ($platforms !== []) {
                    return $platforms;
                }
            }
        }

        $platform = trim((string) config('services.zrno.platform'));
        $accountId = trim((string) config('services.zrno.account_id'));

        if ($platform === '' || $accountId === '') {
            return [];
        }

        return [[
            'platform' => $platform,
            'accountId' => $accountId,
        ]];
    }

    /**
     * @param array<int, array{platform:string,accountId:string}> $platforms
     * @return array{platform:string,accountId:string}
     */
    private function resolveNextPublishPlatform(array $platforms): array
    {
        $lastPublishedPlatform = Script::query()
            ->whereNotNull('published_platform')
            ->orderByDesc('id')
            ->value('published_platform');

        if (! is_string($lastPublishedPlatform) || $lastPublishedPlatform === '') {
            return $platforms[0];
        }

        foreach ($platforms as $index => $platform) {
            if ($platform['platform'] !== $lastPublishedPlatform) {
                continue;
            }

            $nextIndex = ($index + 1) % count($platforms);
            return $platforms[$nextIndex];
        }

        return $platforms[0];
    }
}

