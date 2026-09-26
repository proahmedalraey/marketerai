<?php

namespace App\Services\AI\Drivers\Concerns;

/**
 * نسخة من الدرايفر بنموذج آخر، بنفس المفتاح والمزود.
 *
 * تتيح لخطوة بعينها (كالتدقيق اللغوي) أن تعمل بنموذج أخف وأسرع
 * دون أن يتغير نموذج التوليد الرئيس، ودون لمس منطق أي درايفر.
 */
trait WithModelOverride
{
    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->config['model'] = $model;

        return $clone;
    }
}
