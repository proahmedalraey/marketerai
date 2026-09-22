<?php

namespace App\Services\Import\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * كل طلب خارجي في الاستيراد يمر من هنا.
 *
 * السبب الأمني: الرابط يكتبه المستخدم، والخادم هو من يفتحه.
 * بلا حارس يصير هذا طلباً مزوّراً من الخادم (SSRF) يقرأ خدمات الشبكة
 * الداخلية أو بيانات اعتماد السحابة على 169.254.169.254.
 */
class Fetcher
{
    public const USER_AGENT = 'MarketerAiBot/1.0 (+product import; respects robots)';

    protected const TIMEOUT = 12;
    protected const MAX_BYTES = 3_000_000;

    /**
     * نتيجة الفحص لكل مضيف داخل الطلب الواحد.
     * مسح متجر يفتح مئات الصفحات على المضيف نفسه، وبلا ذاكرة
     * يصير لكل صفحة استعلام DNS مستقل.
     *
     * @var array<string, bool>
     */
    protected array $hostChecks = [];

    /** نطاقات لا يجوز للخادم أن يفتحها مهما كتب المستخدم. */
    protected const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4',
    ];

    public function get(string $url, array $headers = []): ?string
    {
        $url = $this->assertPublicUrl($url);

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT] + $headers)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(6)
                ->withOptions(['allow_redirects' => ['max' => 4, 'strict' => true, 'referer' => false]])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return strlen($body) > self::MAX_BYTES ? substr($body, 0, self::MAX_BYTES) : $body;
    }

    public function getJson(string $url): ?array
    {
        $body = $this->get($url, ['Accept' => 'application/json']);

        if (blank($body)) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    /**
     * يطبّع ما يكتبه المستخدم إلى أصل صالح: يضيف https، ويحذف المسار والاستعلام.
     */
    public function normalizeStoreUrl(string $input): string
    {
        $input = trim($input);

        if (! Str::startsWith($input, ['http://', 'https://'])) {
            $input = 'https://'.ltrim($input, '/');
        }

        $parts = parse_url($input);

        if (empty($parts['host'])) {
            throw new RuntimeException('الرابط غير صالح.');
        }

        $scheme = $parts['scheme'] ?? 'https';

        return $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @throws RuntimeException إن كان الرابط داخلياً أو بمخطط غير مدعوم
     */
    public function assertPublicUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new RuntimeException('نقبل روابط http أو https فقط.');
        }

        $host = $parts['host'];

        // اسم مضيف بلا نقطة = خدمة داخلية على الشبكة نفسها
        if (! str_contains($host, '.') || Str::endsWith($host, ['.local', '.internal', '.localhost'])) {
            throw new RuntimeException('لا يمكن فتح عنوان داخلي.');
        }

        if (! array_key_exists($host, $this->hostChecks)) {
            $this->hostChecks[$host] = collect($this->resolve($host))
                ->every(fn (string $ip) => ! $this->isBlocked($ip));
        }

        if (! $this->hostChecks[$host]) {
            throw new RuntimeException('لا يمكن فتح عنوان داخلي.');
        }

        return $url;
    }

    /** @return array<int, string> */
    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return collect($records)
            ->map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    protected function isBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // ::1 والعناوين المحلية الفريدة
            return Str::startsWith($ip, ['::1', 'fc', 'fd', 'fe80']);
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            [$subnet, $bits] = explode('/', $range);

            $mask = -1 << (32 - (int) $bits);

            if ((ip2long($ip) & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
