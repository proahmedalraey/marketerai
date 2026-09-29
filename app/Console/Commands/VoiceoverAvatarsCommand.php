<?php

namespace App\Console\Commands;

use App\Services\AI\AiManager;
use App\Services\AI\DTO\ImageRequest;
use App\Support\ImageThumbnail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * صور المذيعين في «التعليق الصوتي»: صورة استوديو لكل مذيع تطابق طابع صوته (config/voiceover.php → avatars).
 *
 * أصل ثابت يُولَّد مرة ويُرفع مع الكود (public/images/voices)، لا بيانات متجر: بلا نقاط ولا مهمة،
 * ومن سطر الأوامر لا داخل طلب HTTP. الطلب يمر بـ AiManager فيُسجَّل استهلاكه كبقية الطلبات.
 */
class VoiceoverAvatarsCommand extends Command
{
    protected $signature = 'voiceover:avatars {--voice= : مذيع واحد} {--force : أعد توليد الموجود} {--provider= : مزود الصور (openrouter أو gemini)، الافتراضي مزود الإعدادات}';

    protected $description = 'توليد صور المذيعين في التعليق الصوتي';

    public function handle(AiManager $ai): int
    {
        $looks = config('voiceover.avatars.looks', []);
        $voices = $this->option('voice') ? [$this->option('voice')] : array_keys(config('voiceover.voices', []));
        $directory = public_path('images/voices');
        $failed = 0;

        File::ensureDirectoryExists($directory);

        foreach ($voices as $voice) {
            $path = "{$directory}/{$voice}.jpg";

            if (! isset($looks[$voice])) {
                $this->error("✗ {$voice}: لا وصف له في voiceover.avatars.looks");
                $failed++;

                continue;
            }

            if (! $this->option('force') && File::exists($path)) {
                $this->line("• {$voice} موجودة");

                continue;
            }

            try {
                $response = $ai->generateImage(new ImageRequest(
                    prompt: 'Portrait of '.$looks[$voice].'. '.config('voiceover.avatars.style'),
                    aspectRatio: '1:1',
                    quality: 'standard_1k',
                    operation: 'image.standard_1k',
                    // model يخص OpenRouter؛ Gemini يستعمل image_model من إعداده
                    model: config('voiceover.avatars.model'),
                ), null, $this->option('provider') ?: null);

                $image = $response->images[0] ?? throw new \RuntimeException('لم تُعد صورة');
                $thumb = ImageThumbnail::make($image->contents, (int) config('voiceover.avatars.size', 320));

                File::put($path, $thumb['contents'] ?? $image->contents);
                $this->info("✓ {$voice} (".round(File::size($path) / 1024).' KB)');
            } catch (\Throwable $e) {
                $failed++;
                $this->error("✗ {$voice}: ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
