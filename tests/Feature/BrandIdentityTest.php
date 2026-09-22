<?php

namespace Tests\Feature;

use App\Enums\ColorRole;
use App\Enums\ProfileSource;
use App\Models\Brand;
use App\Models\BrandLogo;
use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function brand(string $name = 'إمدادات القهوة'): Brand
    {
        $user = User::create([
            'name' => 'اختبار',
            'email' => 'identity'.uniqid().'@example.com',
            'password' => 'secret123',
        ]);

        return Brand::create(['user_id' => $user->id, 'name' => $name]);
    }

    protected function logo(Brand $brand, string $label, int $sort = 0): BrandLogo
    {
        Storage::fake('public');

        $path = UploadedFile::fake()
            ->image(str()->slug($label, '-', null).'.png')
            ->store('brands/logos', 'public');

        return BrandLogo::create([
            'brand_id' => $brand->id,
            'path' => $path,
            'label' => $label,
            'sort' => $sort,
        ]);
    }

    // ===================== مكتبة الشعارات =====================

    public function test_first_logo_becomes_the_default_and_mirrors_to_the_brand(): void
    {
        $brand = $this->brand();

        $logo = $this->logo($brand, 'شعار أفقي');

        $this->assertTrue($logo->refresh()->is_default);
        $this->assertSame($logo->path, $brand->refresh()->logo_path);
    }

    public function test_only_one_logo_stays_default(): void
    {
        $brand = $this->brand();
        $first = $this->logo($brand, 'شعار أفقي');
        $second = $this->logo($brand, 'شعار مربع', 1);

        $this->assertFalse($second->refresh()->is_default);

        $second->update(['is_default' => true]);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
        $this->assertSame($second->path, $brand->refresh()->logo_path);
    }

    public function test_deleting_the_default_promotes_the_next_logo(): void
    {
        $brand = $this->brand();
        $first = $this->logo($brand, 'شعار أفقي');
        $second = $this->logo($brand, 'شعار مربع', 1);

        $path = $first->path;
        $first->delete();

        $this->assertTrue($second->refresh()->is_default);
        $this->assertSame($second->path, $brand->refresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_the_last_logo_clears_the_brand_mirror(): void
    {
        $brand = $this->brand();
        $logo = $this->logo($brand, 'شعار وحيد');

        $logo->delete();

        $this->assertNull($brand->refresh()->logo_path);
    }

    // ===================== الألوان =====================

    public function test_palette_reads_roles_from_the_new_shape(): void
    {
        $brand = $this->brand();
        $brand->update(['colors' => [
            ['hex' => '#6f4e37', 'name' => 'بني القهوة', 'role' => 'primary'],
            ['hex' => 'C8A27A', 'name' => null, 'role' => 'accent'],
        ]]);

        $palette = $brand->refresh()->paletteWithRoles();

        $this->assertSame('#6F4E37', $palette[0]['hex']);
        $this->assertSame(ColorRole::Primary, $palette[0]['role']);
        $this->assertSame('#C8A27A', $palette[1]['hex']);
        $this->assertSame('#C8A27A', $brand->colorFor(ColorRole::Accent));
    }

    public function test_palette_still_reads_the_legacy_flat_shape(): void
    {
        $brand = $this->brand();
        $brand->update(['colors' => ['#6F4E37', '#FFFFFF']]);

        $palette = $brand->refresh()->paletteWithRoles();

        $this->assertSame(ColorRole::Primary, $palette[0]['role']);
        $this->assertSame(ColorRole::Secondary, $palette[1]['role']);
    }

    // ===================== نسخ ملف الهوية =====================

    public function test_each_generation_adds_a_version_and_keeps_the_previous_one(): void
    {
        $brand = $this->brand();

        $first = BrandProfile::createVersion($brand, [
            'answers' => ['one_liner' => 'نورّد مستلزمات المقاهي'],
            'simple' => 'النسخة الأولى',
            'source' => ProfileSource::Generated,
        ]);

        $second = BrandProfile::createVersion($brand, [
            'answers' => ['one_liner' => 'نورّد مستلزمات المقاهي'],
            'simple' => 'النسخة الثانية',
            'source' => ProfileSource::Generated,
        ]);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertFalse($first->refresh()->is_active);
        $this->assertTrue($second->refresh()->is_active);
        $this->assertSame('النسخة الثانية', $brand->refresh()->activeProfile->simple);
        $this->assertDatabaseCount('brand_profiles', 2);
    }

    public function test_restoring_copies_the_old_version_to_the_top(): void
    {
        $brand = $this->brand();

        $first = BrandProfile::createVersion($brand, ['simple' => 'النسخة الأولى']);
        BrandProfile::createVersion($brand, ['simple' => 'النسخة الثانية']);

        $restored = $first->restoreAsNewVersion();

        $this->assertSame(3, $restored->version);
        $this->assertSame('النسخة الأولى', $restored->simple);
        $this->assertSame(ProfileSource::Restored, $restored->source);
        // النسخة الثانية لم تُفقد بالاستعادة
        $this->assertDatabaseCount('brand_profiles', 3);
    }

    public function test_pruning_keeps_the_cap_and_never_drops_the_active_version(): void
    {
        config(['brand.profile_versions' => 3]);

        $brand = $this->brand();

        foreach (range(1, 5) as $i) {
            $active = BrandProfile::createVersion($brand, ['simple' => "نسخة {$i}"]);
        }

        $versions = BrandProfile::forBrand($brand)->orderBy('version')->pluck('version')->all();

        $this->assertSame([3, 4, 5], $versions);
        $this->assertTrue($active->refresh()->is_active);
    }

    public function test_technical_block_becomes_a_prompt_fragment_without_the_constraints(): void
    {
        // الرابط حقيقة تُقرأ من العلامة حيّةً، لا من النسخة
        $brand = $this->brand();
        $brand->update(['store_url' => 'https://example.test']);

        $profile = BrandProfile::createVersion($brand, [
            'technical' => [
                'project_type' => 'سلع',
                'project_name' => 'إمدادات القهوة',
                'activity_type' => 'بيع مواد ومعدات الكافيهات',
                'sales_summary' => 'سيروب وصوصات وحبوب قهوة',
                'advantages_directives' => 'وكلاء لعدة علامات عالمية',
                'important_notes' => ['لا تذكر أي تفاصيل غير واردة في المدخلات'],
                'store_url' => 'https://stale-copy.test',
            ],
        ]);

        $fragment = $profile->toPromptFragment();

        $this->assertStringContainsString('نوع المشروع: سلع', $fragment);
        $this->assertStringContainsString('رابط المتجر/الصفحة: https://example.test', $fragment);
        $this->assertStringNotContainsString('stale-copy', $fragment, 'النسخة المخزنة لا تتقدم على العلامة');
        $this->assertStringNotContainsString('لا تذكر أي تفاصيل', $fragment);
        $this->assertSame(['لا تذكر أي تفاصيل غير واردة في المدخلات'], $profile->constraints());
    }

    public function test_unanswered_questions_are_kept_as_text_not_silence(): void
    {
        $brand = $this->brand();

        $profile = BrandProfile::createVersion($brand, [
            'answers' => ['project_name' => 'إمدادات القهوة', 'notes' => ''],
        ]);

        $sheet = collect($profile->answerSheet())->keyBy('question');

        $this->assertTrue($sheet['اسم المشروع']['answered']);
        $this->assertSame(BrandProfile::UNANSWERED, $sheet['ملاحظات إضافية تود تزويدنا فيها']['answer']);
        $this->assertFalse($sheet['ملاحظات إضافية تود تزويدنا فيها']['answered']);
    }
}
