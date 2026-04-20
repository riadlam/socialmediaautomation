<?php

namespace App\Console\Commands;

use App\Models\Script;
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
        $limit = (int) $this->option('limit');

        Script::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Script $script): void {
                try {
                    $response = Http::timeout(60)
                        ->withHeaders([
                            'x-api-key' => (string) config('services.heygen.api_key'),
                            'Content-Type' => 'application/json',
                        ])
                        ->post('https://api.heygen.com/v2/video/generate', [
                            'video_inputs' => [[
                                'character' => [
                                    'type' => 'avatar',
                                    'avatar_id' => (string) config('services.heygen.avatar_id'),
                                ],
                                'voice' => [
                                    'type' => 'text',
                                    'voice_id' => (string) config('services.heygen.voice_id'),
                                    'input_text' => $script->script,
                                ],
                            ]],
                            'title' => 'script-'.$script->id,
                            'callback_id' => (string) $script->id,
                        ]);

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
                        'video_id' => (string) $videoId,
                        'poll_attempts' => 0,
                        'error' => null,
                    ]);
                } catch (Throwable $e) {
                    $this->markError($script, 'HeyGen generate exception: '.$e->getMessage());
                }
            });
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
                    $response = Http::timeout(60)
                        ->withHeaders([
                            'x-api-key' => (string) config('services.heygen.api_key'),
                        ])
                        ->get('https://api.heygen.com/v1/video_status.get', [
                            'video_id' => $script->video_id,
                        ]);

                    $script->increment('poll_attempts');

                    if (! $response->successful()) {
                        $this->markError($script, 'HeyGen status HTTP '.$response->status().': '.$response->body());
                        return;
                    }

                    $status = data_get($response->json(), 'data.status');

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
                    $response = Http::timeout(60)
                        ->withToken((string) config('services.zrno.api_key'))
                        ->post((string) config('services.zrno.base_url').'/v1/posts', [
                            'content' => $script->script,
                            'mediaItems' => [[
                                'type' => 'video',
                                'url' => $script->video_url,
                            ]],
                            'platforms' => [[
                                'platform' => (string) config('services.zrno.platform'),
                                'accountId' => (string) config('services.zrno.account_id'),
                            ]],
                            'publishNow' => true,
                        ]);

                    if (! $response->successful()) {
                        $this->markError($script, 'Zrno publish HTTP '.$response->status().': '.$response->body());
                        return;
                    }

                    $script->update([
                        'status' => 'done',
                        'finish_date' => now(),
                        'publish_response' => $response->json(),
                        'error' => null,
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

        $this->error("Script #{$script->id}: {$message}");
    }
}

