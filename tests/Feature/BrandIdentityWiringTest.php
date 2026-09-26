<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandLogo;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الشكل الجديد للألوان يعبر ثلاث حدود: صفحة الهوية التحريرية،
 * وحفظها، وبرومبت الصور. كسر أيٍّ منها لا يظهر في الصفحة التي غيّرناها.
 */
class BrandIdentityWiringTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::create([
            'name' => 'محمد', 'email' => 'wiring@example.com', 'password' => 'secret123',
        ]);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'industry' => 'توريد مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي',
            'dialect' => 'saudi',
            'colors' => [
                ['hex' => '#6F4E37', 'name' => 'بني القهوة', 'role' => 'primary'],
                ['hex' => '#FFFFFF', 'name' => 'أبيض', 'role' => 'background'],
            ],
            'visual_style' => 'تصوير دافئ بإضاءة طبيعية',
            'design_summary' => 'مساحات بيضاء وخطوط نظيفة.',
            'credit_balance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    public function test_the_identity_page_renders_with_object_shaped_colors(): void
    {
        // كانت الصفحة التحريرية القديمة تسقط بـ 500 لأنها تنفّذ implode على مصفوفة كائنات
        $this->actingAs($this->user)->get('/brand/profile')->assertOk();
    }

    public function test_saving_answers_does_not_wipe_the_visual_identity(): void
    {
        BrandLogo::create([
            'brand_id' => $this->brand->id,
            'path' => 'brands/logo.png',
            'label' => 'شعار أفقي',
        ]);

        $this->actingAs($this->user)->put('/brand/profile/answers', [
            'type' => 'good',
            'project_name' => 'إمدادات القهوة',
            'one_liner' => 'نورّد مستلزمات المقاهي',
            'advantages' => 'توصيل سريع',
            'audience' => 'أصحاب المقاهي والباريستا',
        ])->assertSessionHasNoErrors();

        $brand = $this->brand->refresh();

        $this->assertCount(2, $brand->colors, 'الألوان لا تُمسّ من صفحة الهوية');
        $this->assertSame('بني القهوة', $brand->colors[0]['name']);
        $this->assertSame('مساحات بيضاء وخطوط نظيفة.', $brand->design_summary);
        $this->assertSame('brands/logo.png', $brand->logo_path);
        $this->assertSame(1, $brand->logos()->count());
    }

    public function test_palette_for_prompt_names_the_role_of_each_color(): void
    {
        $this->assertSame(
            '#6F4E37 (primary), #FFFFFF (background)',
            $this->brand->paletteForPrompt(),
        );

        $this->assertNull(Brand::create([
            'user_id' => $this->user->id, 'name' => 'بلا ألوان',
        ])->paletteForPrompt());
    }

    public function test_the_image_prompt_carries_the_palette_and_the_design_direction(): void
    {
        $job = app(ImageGenerationService::class)->dispatch($this->brand, [
            'prompt' => 'كوب قهوة على طاولة خشبية',
            'count' => 1,
        ], $this->user->id);

        $asset = MediaAsset::withoutBrandScope()->where('generation_job_id', $job->id)->first();

        $this->assertNotNull($asset, 'لم تُنتج أي صورة');

        // المعرض والبحث يعرضان وصف المستخدم كما كتبه؛ البرومبت المُركَّب (ألوان واتجاه) يُحفظ في meta
        $this->assertSame('كوب قهوة على طاولة خشبية', $asset->prompt);
        $prompt = $asset->meta['composed_prompt'];

        // الخلل الصامت السابق: implode على مصفوفة كائنات يكتب «Array, Array» في البرومبت
        $this->assertStringNotContainsString('Array', $prompt);
        $this->assertStringContainsString('#6F4E37 (primary)', $prompt);
        $this->assertStringContainsString('تصوير دافئ بإضاءة طبيعية', $prompt);
        $this->assertStringContainsString('مساحات بيضاء وخطوط نظيفة.', $prompt);
    }
}
