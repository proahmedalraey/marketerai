<?php

namespace App\Models;

use App\Enums\ColorRole;
use App\Enums\ProductType;
use App\Services\Content\ContentFacts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'slug', 'industry', 'business_type', 'description',
        'audience', 'tone', 'dialect', 'selling_points', 'banned_words', 'content_rules', 'profile_notes',
        'logo_path', 'colors', 'fonts', 'patterns', 'visual_style', 'design_summary',
        'store_url', 'whatsapp', 'links', 'store_facts',
        'credit_balance', 'credits_allowance', 'credits_reset_at',
        'onboarding_completed',
    ];

    public const PAYMENT_METHODS = [
        'mada' => 'مدى',
        'card' => 'فيزا وماستركارد',
        'apple_pay' => 'Apple Pay',
        'stc_pay' => 'STC Pay',
        'tabby' => 'تابي (تقسيط)',
        'tamara' => 'تمارا (تقسيط)',
        'cod' => 'الدفع عند الاستلام',
        'bank_transfer' => 'تحويل بنكي',
    ];

    protected function casts(): array
    {
        return [
            'selling_points' => 'array',
            'banned_words' => 'array',
            'content_rules' => 'array',
            'colors' => 'array',
            'fonts' => 'array',
            'patterns' => 'array',
            'links' => 'array',
            'store_facts' => 'array',
            'credit_balance' => 'decimal:1',
            'credits_allowance' => 'decimal:1',
            'credits_reset_at' => 'datetime',
            'onboarding_completed' => 'boolean',
            'business_type' => ProductType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Brand $brand) {
            $brand->slug ??= static::uniqueSlug($brand->name);

            // الكاست decimal:1 يُرجع نصاً مثل "0.0" وهو truthy خلافاً للصفر الصحيح،
            // لذا فحص رقمي صريح بدل ?: حتى لا يُفلت رصيد ابتدائي صفري من قيمته الافتراضية.
            if ((float) $brand->credits_allowance <= 0) {
                $brand->credits_allowance = config('credits.monthly_allowance');
            }

            if ((float) $brand->credit_balance <= 0) {
                $brand->credit_balance = $brand->credits_allowance;
            }

            $brand->credits_reset_at ??= now()->addMonth();
        });
    }

    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function generationJobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function storeIntegrations(): HasMany
    {
        return $this->hasMany(StoreIntegration::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function testimonials(): HasMany
    {
        return $this->hasMany(Testimonial::class);
    }

    /**
     * حقائق المتجر العامة بجمل موجبة، كما تدخل البرومبت ودفتر الحقائق.
     *
     * ما لم يُحدده التاجر لا يُكتب إطلاقاً، ولا يُكتب نفياً: «لا شحن مجاني»
     * تحوي «مجاني»، فتصير عند الفحص إذناً بذكره.
     *
     * @return array<int, string>
     */
    public function storeFactLines(): array
    {
        $facts = (array) $this->store_facts;
        $text = fn ($key) => trim((string) ($facts[$key] ?? ''));

        $channels = array_values(array_intersect(['retail', 'wholesale'], (array) ($facts['channels'] ?? [])));

        $payments = collect((array) ($facts['payments'] ?? []))
            ->map(fn ($key) => self::PAYMENT_METHODS[$key] ?? null)
            ->filter()
            ->implode('، ');

        $over = (float) ($facts['free_shipping_over'] ?? 0);

        return array_values(array_filter([
            $text('delivery') !== '' ? 'التوصيل والشحن: '.$text('delivery') : null,
            match ($facts['free_shipping'] ?? null) {
                'always' => 'شحن مجاني لكل الطلبات',
                'over' => $over > 0 ? 'شحن مجاني للطلبات فوق '.ContentFacts::canonical((string) $over).' ريال' : null,
                default => null,
            },
            $text('branches') !== '' ? 'الفروع: '.$text('branches') : null,
            match ($channels) {
                ['retail', 'wholesale'] => 'البيع بالتجزئة والجملة',
                ['wholesale'] => 'البيع بالجملة',
                ['retail'] => 'البيع بالتجزئة',
                default => null,
            },
            $payments !== '' ? 'طرق الدفع: '.$payments : null,
            $text('returns') !== '' ? 'الاسترجاع والاستبدال: '.$text('returns') : null,
        ]));
    }

    public function logos(): HasMany
    {
        return $this->hasMany(BrandLogo::class)->orderBy('sort')->orderBy('id');
    }

    public function defaultLogo(): HasOne
    {
        return $this->hasOne(BrandLogo::class)->where('is_default', true);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(BrandProfile::class)->orderByDesc('version');
    }

    public function activeProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class)->where('is_active', true);
    }

    /**
     * ألوان العلامة بأدوارها.
     *
     * تقرأ الشكلين: مصفوفة أكواد (ما قبل الترقية) ومصفوفة كائنات.
     * الصفوف القديمة تصل إلى هنا حين تُقرأ من كاش أو نسخة احتياطية.
     *
     * @return array<int, array{hex: string, name: ?string, role: ColorRole}>
     */
    public function paletteWithRoles(): array
    {
        return collect($this->colors ?? [])
            ->map(function ($color, $index) {
                $hex = is_array($color) ? ($color['hex'] ?? null) : $color;

                if (! is_string($hex) || trim($hex) === '') {
                    return null;
                }

                $role = is_array($color) ? ($color['role'] ?? null) : null;

                return [
                    'hex' => strtoupper(str_starts_with(trim($hex), '#') ? trim($hex) : '#'.trim($hex)),
                    'name' => is_array($color) ? ($color['name'] ?? null) : null,
                    'role' => ColorRole::tryFrom((string) $role)
                        ?? ($index === 0 ? ColorRole::Primary : ColorRole::Secondary),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * وصف اللوحة كما يُقرأ في برومبت الصور.
     * الكود وحده لا يكفي: النموذج يحتاج أن يعرف أين يضع كل لون.
     */
    public function paletteForPrompt(int $limit = 3): ?string
    {
        $palette = collect($this->paletteWithRoles())->take($limit);

        return $palette->isEmpty()
            ? null
            : $palette->map(fn ($c) => $c['hex'].' ('.$c['role']->value.')')->implode(', ');
    }

    public function colorFor(ColorRole $role): ?string
    {
        return collect($this->paletteWithRoles())
            ->firstWhere('role', $role)['hex'] ?? null;
    }

    public function font(string $slot): ?string
    {
        $value = $this->fonts[$slot] ?? null;

        return filled($value) ? (string) $value : null;
    }

    /** هل للعلامة هوية بصرية تكفي لتوجيه نموذج الصور؟ */
    public function hasVisualIdentity(): bool
    {
        return $this->logos()->exists()
            || $this->paletteWithRoles() !== []
            || filled($this->design_summary);
    }

    /**
     * المنتج المرجعي الأساسي: يُختار افتراضياً في «كتابة المحتوى».
     */
    public function primaryProduct(): ?Product
    {
        return $this->products()->where('is_primary', true)->first()
            ?? $this->products()->oldest('id')->first();
    }

    /**
     * «المجال» لم يعد سؤالاً: يشتقه التحليل من الإجابات ويكتبه هنا.
     */
    public function isReadyForGeneration(): bool
    {
        return filled($this->name) && filled($this->audience);
    }

    /**
     * إجابات المشروع بصيغتها القياسية، من أعمدة العلامة مباشرة.
     *
     * هذه هي المدخلات الوحيدة للتوليد، وهي نفسها ما يُلتقط في كل نسخة.
     * مقارنة اللقطة بها تكشف إن كانت الأوصاف متأخرة عن آخر تعديل.
     *
     * @return array<string, string>
     */
    public function profileAnswers(): array
    {
        return [
            'type' => ($this->business_type ?? ProductType::Good)->value,
            'project_name' => trim((string) $this->name),
            'store_url' => trim((string) $this->store_url),
            'one_liner' => trim((string) $this->description),
            'advantages' => implode("\n", (array) $this->selling_points),
            'audience' => trim((string) $this->audience),
            'notes' => trim((string) $this->profile_notes),
        ];
    }
}
