<?php

namespace Tests\Feature;

use App\Enums\ColorRole;
use App\Models\Brand;
use App\Models\BrandLogo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualIdentityPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::create([
            'name' => 'محمد', 'email' => 'identity@example.com', 'password' => 'secret123',
        ]);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'industry' => 'توريد مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي',
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    // ===================== الألوان والخطوط =====================

    public function test_it_saves_colors_with_names_and_roles(): void
    {
        $this->actingAs($this->user)->post('/brand/identity', [
            'colors' => [
                ['hex' => '6f4e37', 'name' => 'بني القهوة', 'role' => 'primary'],
                ['hex' => '#fff', 'name' => '', 'role' => 'background'],
            ],
            'fonts' => ['ar_primary' => 'Cairo', 'en_primary' => '', 'ar_secondary' => '', 'en_secondary' => ''],
            'design_summary' => 'تصاميم هادئة بمساحات بيضاء.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $brand = $this->brand->refresh();

        // الصيغ الثلاث للكود تُكتب بشكل واحد: بلا # وبثلاث خانات وبحروف صغيرة
        $this->assertSame('#6F4E37', $brand->colors[0]['hex']);
        $this->assertSame('#FFFFFF', $brand->colors[1]['hex']);
        $this->assertSame('بني القهوة', $brand->colors[0]['name']);
        $this->assertSame(ColorRole::Background, $brand->paletteWithRoles()[1]['role']);

        $this->assertSame(['ar_primary' => 'Cairo'], $brand->fonts, 'الخانات الفارغة لا تُحفظ');
        $this->assertSame('تصاميم هادئة بمساحات بيضاء.', $brand->design_summary);
    }

    public function test_a_row_without_a_hex_is_dropped_not_saved_empty(): void
    {
        $this->actingAs($this->user)->post('/brand/identity', [
            'colors' => [
                ['hex' => '#111827', 'name' => 'أسود', 'role' => 'text'],
                ['hex' => '', 'name' => 'لون بلا كود', 'role' => 'accent'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->brand->refresh()->colors);
    }

    public function test_it_rejects_a_malformed_hex(): void
    {
        $this->actingAs($this->user)
            ->post('/brand/identity', ['colors' => [['hex' => 'قهوة', 'role' => 'primary']]])
            ->assertSessionHasErrors('colors.0.hex');
    }

    // ===================== مكتبة الشعارات =====================

    public function test_uploading_a_logo_fills_the_library_and_the_brand_mirror(): void
    {
        $this->actingAs($this->user)->post('/brand/logos', [
            'logo' => UploadedFile::fake()->image('logo.png'),
            'label' => 'شعار أفقي',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $logo = BrandLogo::withoutBrandScope()->firstOrFail();

        $this->assertTrue($logo->is_default, 'أول شعار يجب أن يكون المفضّل');
        $this->assertSame($logo->path, $this->brand->refresh()->logo_path);
        Storage::disk('public')->assertExists($logo->path);
    }

    public function test_marking_another_logo_as_default_moves_the_badge(): void
    {
        $this->actingAs($this->user)->post('/brand/logos', ['logo' => UploadedFile::fake()->image('a.png')]);
        $this->actingAs($this->user)->post('/brand/logos', ['logo' => UploadedFile::fake()->image('b.png')]);

        [$first, $second] = BrandLogo::withoutBrandScope()->orderBy('id')->get()->all();

        $this->actingAs($this->user)
            ->patch("/brand/logos/{$second->id}", ['is_default' => '1', 'label' => 'شعار مربع'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
        $this->assertSame('شعار مربع', $second->label);
        $this->assertSame($second->path, $this->brand->refresh()->logo_path);
    }

    public function test_the_logo_library_has_a_ceiling(): void
    {
        config(['brand.logos_max' => 2]);

        foreach (range(1, 2) as $i) {
            $this->actingAs($this->user)->post('/brand/logos', ['logo' => UploadedFile::fake()->image("l{$i}.png")]);
        }

        $this->actingAs($this->user)
            ->post('/brand/logos', ['logo' => UploadedFile::fake()->image('l3.png')])
            ->assertSessionHasErrors('logo');

        $this->assertSame(2, BrandLogo::withoutBrandScope()->count());
    }

    public function test_a_logo_of_another_brand_is_not_reachable(): void
    {
        $intruder = User::create(['name' => 'دخيل', 'email' => 'x@example.com', 'password' => 'secret123']);
        $otherBrand = Brand::create(['user_id' => $intruder->id, 'name' => 'علامة أخرى', 'onboarding_completed' => true]);
        $intruder->update(['current_brand_id' => $otherBrand->id]);

        $this->actingAs($this->user)->post('/brand/logos', ['logo' => UploadedFile::fake()->image('mine.png')]);
        $logo = BrandLogo::withoutBrandScope()->firstOrFail();

        $this->actingAs($intruder)->delete("/brand/logos/{$logo->id}")->assertNotFound();

        $this->assertDatabaseHas('brand_logos', ['id' => $logo->id]);
    }

    // ===================== الأنماط =====================

    public function test_patterns_upload_and_delete_by_position(): void
    {
        $this->actingAs($this->user)->post('/brand/identity/patterns', [
            'patterns' => [
                UploadedFile::fake()->image('p1.png'),
                UploadedFile::fake()->image('p2.png'),
            ],
        ])->assertSessionHasNoErrors();

        $patterns = $this->brand->refresh()->patterns;
        $this->assertCount(2, $patterns);
        $removed = $patterns[0]['path'];

        $this->actingAs($this->user)->delete('/brand/identity/patterns/0')->assertSessionHasNoErrors();

        $patterns = $this->brand->refresh()->patterns;

        $this->assertCount(1, $patterns);
        $this->assertSame(0, $patterns[0]['sort'], 'الترتيب يُعاد بعد الحذف');
        Storage::disk('public')->assertMissing($removed);
    }

    public function test_patterns_respect_the_remaining_slots_not_just_the_batch_size(): void
    {
        config(['brand.patterns_max' => 3]);

        $this->actingAs($this->user)->post('/brand/identity/patterns', [
            'patterns' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')],
        ]);

        $this->actingAs($this->user)->post('/brand/identity/patterns', [
            'patterns' => [UploadedFile::fake()->image('c.png'), UploadedFile::fake()->image('d.png')],
        ])->assertSessionHasErrors('patterns');

        $this->assertCount(2, $this->brand->refresh()->patterns);
    }

    // ===================== الصفحة =====================

    public function test_the_panel_shows_saved_identity(): void
    {
        $this->brand->update([
            'colors' => [['hex' => '#6F4E37', 'name' => 'بني القهوة', 'role' => 'primary']],
            'fonts' => ['ar_primary' => 'Cairo'],
            'design_summary' => 'خلاصة تصميمية محفوظة.',
        ]);

        $this->actingAs($this->user)->post('/brand/logos', [
            'logo' => UploadedFile::fake()->image('logo.png'), 'label' => 'شعار أفقي',
        ]);

        $html = $this->actingAs($this->user)->get('/brand/identity')->assertOk()->getContent();

        // صفوف الألوان تُبنى في ألبين، فتصل كحمولة JSON.parse لا كنص ظاهر:
        // العربية تُرمَّز \uXXXX، والشرطة المائلة تُضاعَف لأنها داخل نص جافاسكربت
        $expectedName = str_replace('\\', '\\\\', trim(json_encode('بني القهوة'), '"'));

        $this->assertStringContainsString('#6F4E37', $html);
        $this->assertStringContainsString($expectedName, $html);

        $this->assertStringContainsString('Cairo', $html);
        $this->assertStringContainsString('خلاصة تصميمية محفوظة.', $html);
        $this->assertStringContainsString('شعار أفقي', $html);
        $this->assertStringContainsString('المفضل', $html);
    }
}
