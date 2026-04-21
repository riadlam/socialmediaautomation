<?php

namespace App\Console\Commands;

use App\Models\Script;
use App\Models\ScriptLog;
use Illuminate\Console\Command;
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
                    $inputText = $this->trimScriptToTargetDuration($script->script);
                    $payload = [
                        'video_inputs' => [[
                            'character' => [
                                'type' => 'avatar',
                                'avatar_id' => (string) config('services.heygen.avatar_id'),
                            ],
                            'voice' => [
                                'type' => 'text',
                                'input_text' => $inputText,
                            ],
                        ]],
                        'title' => 'script-'.$script->id,
                        'callback_id' => (string) $script->id,
                    ];

                    if (filled(config('services.heygen.voice_id'))) {
                        $payload['video_inputs'][0]['voice']['voice_id'] = (string) config('services.heygen.voice_id');
                    }

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

                    $response = Http::timeout(60)
                        ->withToken((string) config('services.zrno.api_key'))
                        ->post((string) config('services.zrno.base_url').'/v1/posts', [
                            'content' => $script->script,
                            'mediaItems' => [[
                                'type' => 'video',
                                'url' => $script->video_url,
                            ]],
                            'platforms' => [$selectedPlatform],
                            'publishNow' => true,
                        ]);

                    if (! $response->successful()) {
                        $this->markError($script, 'Zrno publish HTTP '.$response->status().': '.$response->body());
                        return;
                    }

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
                } catch (Throwable $e) {
                    $this->markError($script, 'Zrno publish exception: '.$e->getMessage());
                }
            });
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

    private function trimScriptToTargetDuration(string $script): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $script) ?? $script);
        $targetSeconds = (int) config('services.heygen.target_seconds', 20);
        $wpm = (int) config('services.heygen.words_per_minute', 150);
        $maxWords = max(1, (int) floor(($targetSeconds / 60) * $wpm));
        $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($words) || count($words) <= $maxWords) {
            return $clean;
        }

        return implode(' ', array_slice($words, 0, $maxWords));
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

