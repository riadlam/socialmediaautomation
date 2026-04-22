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

    protected $description = 'Process scripts table: HeyGen Video Agent prompt-to-video (POST /v3/video-agents), poll session + GET /v3/videos/{id}, then publish to Zrno.';

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
                    $this->writeLog($script, 'generate', 'info', 'Started HeyGen Video Agent (POST /v3/video-agents).');
                    $payload = $this->buildHeyGenVideoAgentPayload($script);

                    $response = Http::timeout(120)
                        ->withHeaders([
                            'x-api-key' => (string) config('services.heygen.api_key'),
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ])
                        ->post('https://api.heygen.com/v3/video-agents', $payload);

                    if (! $response->successful()) {
                        $this->markError($script, 'HeyGen Video Agent HTTP '.$response->status().': '.$response->body());
                        return;
                    }

                    $createJson = $response->json();
                    $sessionId = $this->extractHeyGenVideoAgentSessionId($createJson);
                    if (! $sessionId) {
                        $this->markError($script, 'HeyGen Video Agent: missing session_id in response.');
                        return;
                    }

                    $videoId = $this->extractHeyGenVideoAgentVideoIdFromCreate($createJson);

                    $script->update([
                        'status' => 'generating',
                        'start_date' => $script->start_date ?? now(),
                        'finish_date' => null,
                        'heygen_session_id' => (string) $sessionId,
                        'video_id' => $videoId !== null && $videoId !== '' ? (string) $videoId : null,
                        'video_url' => null,
                        'poll_attempts' => 0,
                        'error' => null,
                    ]);

                    $this->writeLog($script, 'generate', 'info', 'HeyGen Video Agent session created.', [
                        'session_id' => (string) $sessionId,
                        'video_id' => $videoId,
                        'orientation' => (string) ($payload['orientation'] ?? ''),
                        'mode' => (string) ($payload['mode'] ?? ''),
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
                if (! filled($script->heygen_session_id) && ! filled($script->video_id)) {
                    $this->markError($script, 'Missing heygen_session_id and video_id while generating.');
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

                    $videoId = filled($script->video_id) ? (string) $script->video_id : null;

                    if ($videoId === null && filled($script->heygen_session_id)) {
                        $sessionUrl = 'https://api.heygen.com/v3/video-agents/'.rawurlencode((string) $script->heygen_session_id);
                        $sessionResponse = Http::timeout(60)
                            ->withHeaders([
                                'x-api-key' => (string) config('services.heygen.api_key'),
                                'Accept' => 'application/json',
                            ])
                            ->get($sessionUrl);

                        if (! $sessionResponse->successful()) {
                            $this->markError($script, 'HeyGen Video Agent session HTTP '.$sessionResponse->status().': '.$sessionResponse->body());
                            return;
                        }

                        $sessionJson = $sessionResponse->json();
                        if (is_array($sessionJson) && data_get($sessionJson, 'error.code')) {
                            $this->markError($script, 'HeyGen session API error: '.(string) (data_get($sessionJson, 'error.message') ?: $sessionResponse->body()));
                            return;
                        }

                        $sessionData = is_array($sessionJson) ? data_get($sessionJson, 'data') : null;
                        $sessionData = is_array($sessionData) ? $sessionData : [];
                        $sessionStatus = strtolower((string) data_get($sessionData, 'status', ''));

                        if ($sessionStatus === 'failed') {
                            $reason = $this->extractVideoAgentSessionFailureReason($sessionData)
                                ?? 'HeyGen Video Agent session failed.';
                            $this->markError($script, $reason);
                            return;
                        }

                        $assignedId = data_get($sessionData, 'video_id');
                        if (is_string($assignedId) && $assignedId !== '') {
                            $script->update(['video_id' => $assignedId]);
                            $videoId = $assignedId;
                            $this->writeLog($script, 'poll', 'info', 'HeyGen Video Agent assigned video_id.', [
                                'video_id' => $assignedId,
                                'session_status' => $sessionStatus,
                                'poll_attempts' => $script->fresh()->poll_attempts,
                            ]);
                        } else {
                            $this->writeLog($script, 'poll', 'info', 'HeyGen Video Agent session polled.', [
                                'session_status' => $sessionStatus,
                                'poll_attempts' => $script->fresh()->poll_attempts,
                            ]);
                            return;
                        }
                    }

                    $pollUrl = 'https://api.heygen.com/v3/videos/'.rawurlencode((string) $videoId);
                    $response = Http::timeout(60)
                        ->withHeaders([
                            'x-api-key' => (string) config('services.heygen.api_key'),
                            'Accept' => 'application/json',
                        ])
                        ->get($pollUrl);

                    if (! $response->successful()) {
                        $this->markError($script, 'HeyGen video status HTTP '.$response->status().': '.$response->body());
                        return;
                    }

                    $pollJson = $response->json();
                    if (is_array($pollJson) && data_get($pollJson, 'error.code')) {
                        $this->markError($script, 'HeyGen status API error: '.(string) (data_get($pollJson, 'error.message') ?: $response->body()));
                        return;
                    }

                    $state = $this->parseHeyGenV2VideoPollState($pollJson);
                    $status = $state['status'];
                    $this->writeLog($script, 'poll', 'info', 'HeyGen video status polled.', [
                        'video_id' => $videoId,
                        'poll_attempts' => $script->fresh()->poll_attempts,
                        'status' => $status,
                    ]);

                    $statusLower = is_string($status) ? strtolower($status) : '';

                    if (in_array($statusLower, ['completed', 'complete', 'succeeded', 'success'], true)) {
                        $videoUrl = $this->resolveHeyGenCompletedVideoUrl($state);

                        if (! $videoUrl) {
                            $this->markError($script, 'HeyGen completed but no usable video URL (video_url / captioned_video_url / video_url_caption).');
                            return;
                        }

                        $script->update([
                            'status' => 'publishing',
                            'video_url' => $videoUrl,
                            'error' => null,
                        ]);
                        $this->writeLog($script, 'poll', 'info', 'HeyGen video completed.', [
                            'video_url' => $videoUrl,
                            'used_caption_render' => $videoUrl === ($state['captioned_video_url'] ?? null)
                                || $videoUrl === ($state['video_url_caption'] ?? null),
                        ]);
                        return;
                    }

                    if (in_array($statusLower, ['failed', 'error'], true)) {
                        $failedReason = $this->formatHeyGenPollFailureReason($state['error'])
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
     * POST /v3/video-agents — HeyGen Video Agent (prompt-to-video, dashboard-style).
     *
     * @return array<string, mixed>
     */
    private function buildHeyGenVideoAgentPayload(Script $script): array
    {
        $core = trim((string) $script->script);
        $core = $this->buildHeyGenCreateVideoScriptText($core);
        if ($core === '') {
            $core = 'Brief engaging vertical social video.';
        }

        $prompt = $core.$this->heyGenVideoAgentPromptSuffix();
        if (mb_strlen($prompt) > 10000) {
            $prompt = mb_substr($prompt, 0, 10000);
        }

        $payload = [
            'prompt' => $prompt,
            'mode' => 'generate',
            'orientation' => $this->heyGenVideoAgentOrientation(),
            'incognito_mode' => false,
        ];

        if (filled(config('services.heygen.avatar_id'))) {
            $payload['avatar_id'] = (string) config('services.heygen.avatar_id');
        }

        if (filled(config('services.heygen.voice_id'))) {
            $payload['voice_id'] = (string) config('services.heygen.voice_id');
        }

        if (filled(config('services.heygen.video_agent_style_id'))) {
            $payload['style_id'] = (string) config('services.heygen.video_agent_style_id');
        }

        return $payload;
    }

    private function heyGenVideoAgentOrientation(): string
    {
        $aspect = strtolower(trim((string) config('services.heygen.aspect_ratio', '9:16')));
        if ($aspect === '16:9') {
            return 'landscape';
        }

        return 'portrait';
    }

    private function heyGenVideoAgentPromptSuffix(): string
    {
        $parts = [
            "\n\n---\nProduction brief (follow closely):",
            'Target: vertical 9:16 short-form (TikTok / Instagram Reels / Shorts).',
            'Use multiple scenes with tasteful cuts and transitions where they help the message (not one static talking-head shot unless the idea is extremely short).',
            'Strong hook in the first seconds; clear pacing for mobile viewers.',
        ];

        if ((bool) config('services.heygen.caption', true)) {
            $parts[] = 'Include burned-in, readable on-screen subtitles for all spoken words (high contrast, social-safe placement).';
        }

        return "\n".implode("\n", $parts);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function extractHeyGenVideoAgentSessionId(?array $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $id = data_get($json, 'data.session_id') ?? data_get($json, 'session_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function extractHeyGenVideoAgentVideoIdFromCreate(?array $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $id = data_get($json, 'data.video_id') ?? data_get($json, 'video_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function extractVideoAgentSessionFailureReason(array $sessionData): ?string
    {
        $messages = data_get($sessionData, 'messages');
        if (! is_array($messages)) {
            return null;
        }

        $parts = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            if (strtolower((string) data_get($message, 'type')) !== 'error') {
                continue;
            }
            $content = data_get($message, 'content');
            if (is_string($content) && $content !== '') {
                $parts[] = $content;
            }
        }

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return null;
    }

    private function buildHeyGenCreateVideoScriptText(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if ((bool) config('services.heygen.multi_scene', true)) {
            $parts = $this->splitScriptIntoScenes($raw);
            if (count($parts) >= 2) {
                $joined = implode("\n\n", $parts);

                return $this->trimScriptToTargetDuration($joined);
            }
        }

        return $this->trimScriptToTargetDuration($raw);
    }

    /**
     * Parses GET /v3/videos/{id} VideoDetail (`data`) or legacy GET /v1/video_status.get (`code` + `data`).
     *
     * @param array<string, mixed>|null $json
     *
     * @return array{status: ?string, video_url: ?string, captioned_video_url: ?string, video_url_caption: ?string, error: mixed}
     */
    private function parseHeyGenV2VideoPollState(?array $json): array
    {
        if (! is_array($json)) {
            return ['status' => null, 'video_url' => null, 'captioned_video_url' => null, 'video_url_caption' => null, 'error' => null];
        }

        $data = data_get($json, 'data');
        $block = is_array($data) ? $data : $json;

        $failureMessage = data_get($block, 'failure_message');
        $legacyError = data_get($block, 'error') ?? data_get($block, 'message') ?? data_get($json, 'error');

        return [
            'status' => $this->scalarToNullableString(data_get($block, 'status')),
            'video_url' => $this->scalarToNullableString(
                data_get($block, 'video_url')
                    ?? data_get($block, 'url')
                    ?? data_get($block, 'video.video_url')
            ),
            'captioned_video_url' => $this->scalarToNullableString(data_get($block, 'captioned_video_url')),
            'video_url_caption' => $this->scalarToNullableString(data_get($block, 'video_url_caption')),
            'error' => $failureMessage !== null && $failureMessage !== '' ? $failureMessage : $legacyError,
        ];
    }

    /**
     * When captions are enabled, prefer HeyGen’s burned-in MP4 (captioned_video_url or legacy video_url_caption), else plain video_url.
     *
     * @param array{status: ?string, video_url: ?string, captioned_video_url: ?string, video_url_caption: ?string, error: mixed} $state
     */
    private function resolveHeyGenCompletedVideoUrl(array $state): ?string
    {
        $wantCaptions = (bool) config('services.heygen.caption', true);
        $captioned = $state['captioned_video_url'] ?? null;
        $legacyCaption = $state['video_url_caption'] ?? null;
        $plainUrl = $state['video_url'] ?? null;

        if ($wantCaptions) {
            if (is_string($captioned) && $captioned !== '') {
                return $captioned;
            }
            if (is_string($legacyCaption) && $legacyCaption !== '') {
                return $legacyCaption;
            }
        }

        return is_string($plainUrl) && $plainUrl !== '' ? $plainUrl : null;
    }

    private function scalarToNullableString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function formatHeyGenPollFailureReason(mixed $error): ?string
    {
        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (is_array($error)) {
            $msg = data_get($error, 'message') ?? data_get($error, 'detail');
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }

            return json_encode($error, JSON_UNESCAPED_UNICODE) ?: 'HeyGen render failed.';
        }

        return null;
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

