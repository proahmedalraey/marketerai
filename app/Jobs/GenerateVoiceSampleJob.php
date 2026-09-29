<?php

namespace App\Jobs;

use App\Services\AI\ProviderException;
use App\Services\Voiceover\VoiceSamples;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** عينة «استمع» لمذيع: مشتركة بين المتاجر، بلا نقاط ولا سجل مهام للتاجر. */
class GenerateVoiceSampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $voice, public string $language) {}

    public function handle(VoiceSamples $samples): void
    {
        if ($samples->url($this->voice, $this->language)) {
            $samples->forgetPending($this->voice, $this->language);

            return;
        }

        try {
            $samples->generate($this->voice, $this->language);
        } catch (\Throwable $e) {
            // يُفتح الطريق لمحاولة لاحقة من الواجهة بدل انتظار انتهاء القفل
            $samples->forgetPending($this->voice, $this->language);
            $samples->markFailed($this->voice, $this->language, $e instanceof ProviderException && $e->quotaExhausted
                ? 'عينة هذا المذيع لم تُجهَّز بعد: انتهت حصة مزود الصوت اليومية. جرّب لاحقاً.'
                : 'تعذّر تجهيز عينة هذا المذيع الآن. جرّب بعد قليل.');
            Log::warning('تعذّر توليد عينة صوت', ['voice' => $this->voice, 'language' => $this->language, 'error' => $e->getMessage()]);
        }
    }
}
