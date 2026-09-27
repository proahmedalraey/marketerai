<?php

namespace App\Console\Commands;

use App\Services\Voiceover\VoiceSamples;
use Illuminate\Console\Command;

/**
 * يولّد عينات «استمع» لكل المذيعين مسبقاً، فلا ينتظر أول تاجر ثوانٍ عند كل صوت.
 * يعمل من سطر الأوامر لا داخل طلب HTTP، فلا يخالف قاعدة الطابور.
 */
class VoiceoverSamplesCommand extends Command
{
    protected $signature = 'voiceover:samples {--language=ar : ar أو en أو all} {--voice= : مذيع واحد} {--force : أعد توليد الموجود}';

    protected $description = 'توليد عينات أصوات المذيعين في التعليق الصوتي';

    public function handle(VoiceSamples $samples): int
    {
        $languages = match ($this->option('language')) {
            'all' => ['ar', 'en'],
            'en' => ['en'],
            default => ['ar'],
        };

        $voices = $this->option('voice') ? [$this->option('voice')] : array_keys(config('voiceover.voices', []));
        $failed = 0;

        foreach ($languages as $language) {
            foreach ($voices as $voice) {
                if (! $this->option('force') && $samples->url($voice, $language)) {
                    $this->line("• {$voice} ({$language}) موجودة");

                    continue;
                }

                try {
                    $samples->generate($voice, $language);
                    $this->info("✓ {$voice} ({$language})");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("✗ {$voice} ({$language}): ".$e->getMessage());
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
