<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Services\AI\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * «عبّئ الأسئلة من رابط المتجر»: يقرأ الصفحة ويقترح إجابات، ولا يحفظ شيئاً.
 */
class BrandPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected const STORE = 'https://coffee.example.test/ar';

    protected const PAGE = <<<'HTML'
        <html><head>
            <title>امدادات القهوة | مستلزمات الكافيهات</title>
            <meta name="description" content="وكلاء لعلامات عالمية في السيروب والصوص وحبوب القهوة. توصيل مجاني للمقاهي داخل المدينة.">
            <script>var tracking = "SECRET_TRACKER_CODE";</script>
            <style>.x{color:red}</style>
        </head><body>
            <nav>الرئيسية | السلة | حسابي</nav>
            <h1>كل ما يحتاجه مقهاك في مكان واحد</h1>
            <p>نوفر السيروب والصوص والحشوات والبودرة وحبوب القهوة وأدوات الباريستا لأصحاب المقاهي والمطاعم.</p>
            <p>البيع بالجملة والتجزئة، وصيانة مكائن القهوة.</p>
        </body></html>
        HTML;

    protected ScriptedAiManager $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = ScriptedAiManager::install();
    }

    protected function userWithBrand(int $balance = 100): array
    {
        $user = User::create(['name' => 'م', 'email' => 'prefill@example.com', 'password' => 'secret123']);
        $brand = Brand::create([
            'user_id' => $user->id, 'name' => 'امدادات القهوة', 'audience' => 'أصحاب المقاهي',
            'credit_balance' => $balance, 'credits_allowance' => 100, 'onboarding_completed' => true,
        ]);
        $user->update(['current_brand_id' => $brand->id]);

        return [$user, $brand];
    }

    protected function suggestion(): array
    {
        return [
            'project_name' => 'امدادات القهوة',
            'one_liner' => 'نبيع السيروب والصوص والحشوات والبودرة وحبوب القهوة وأدوات الباريستا',
            'advantages' => ['وكلاء لعلامات عالمية', 'توصيل مجاني للمقاهي داخل المدينة', 'البيع بالجملة والتجزئة'],
            'audience' => 'أصحاب المقاهي والمطاعم',
        ];
    }

    public function test_it_suggests_answers_from_the_store_page_and_charges_one_credit(): void
    {
        [$user, $brand] = $this->userWithBrand();
        Http::fake([self::STORE => Http::response(self::PAGE)]);
        $this->ai->replyWith($this->suggestion());

        $this->actingAs($user)->postJson('/brand/profile/prefill', ['store_url' => self::STORE])
            ->assertOk()
            ->assertJsonPath('fields.one_liner', 'نبيع السيروب والصوص والحشوات والبودرة وحبوب القهوة وأدوات الباريستا')
            ->assertJsonPath('fields.advantages', "وكلاء لعلامات عالمية\nتوصيل مجاني للمقاهي داخل المدينة\nالبيع بالجملة والتجزئة")
            ->assertJsonPath('credits_charged', 1);

        $this->assertSame(99, $brand->refresh()->credit_balance);

        // ما يصل للنموذج: نص الصفحة ووصفها، لا الشيفرة ولا قائمة التنقل
        $prompt = $this->ai->lastRequest()->prompt;
        $this->assertStringContainsString('وكلاء لعلامات عالمية في السيروب', $prompt);
        $this->assertStringContainsString('كل ما يحتاجه مقهاك', $prompt);
        $this->assertStringNotContainsString('SECRET_TRACKER_CODE', $prompt);
        $this->assertStringNotContainsString('حسابي', $prompt);

        // اقتراح لا حفظ: العلامة لم تتغير
        $this->assertNull($brand->refresh()->description);
    }

    public function test_an_unreadable_page_costs_nothing(): void
    {
        [$user, $brand] = $this->userWithBrand();
        Http::fake([self::STORE => Http::response('Not found', 404)]);

        $this->actingAs($user)->postJson('/brand/profile/prefill', ['store_url' => self::STORE])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'تعذّرت قراءة الصفحة') && str_contains($m, 'لم تُخصم'));

        $this->assertSame(100, $brand->refresh()->credit_balance);
        $this->assertSame([], $this->ai->requests, 'لا استدعاء للنموذج على صفحة فارغة');
    }

    public function test_an_exhausted_ai_quota_refunds_and_explains(): void
    {
        [$user, $brand] = $this->userWithBrand();
        Http::fake([self::STORE => Http::response(self::PAGE)]);
        $this->ai->replyWith(new ProviderException('429', 'gemini', 429, false, true));

        $this->actingAs($user)->postJson('/brand/profile/prefill', ['store_url' => self::STORE])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'نفدت الحصة'));

        $this->assertSame(100, $brand->refresh()->credit_balance);
    }

    public function test_nothing_found_on_the_page_is_reported_not_filled_with_blanks(): void
    {
        [$user] = $this->userWithBrand();
        Http::fake([self::STORE => Http::response(self::PAGE)]);
        $this->ai->replyWith(['project_name' => '', 'one_liner' => '', 'advantages' => [], 'audience' => '']);

        $this->actingAs($user)->postJson('/brand/profile/prefill', ['store_url' => self::STORE])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'لم نجد'));
    }

    public function test_a_new_user_without_a_brand_can_prefill_for_free(): void
    {
        $newcomer = User::create(['name' => 'جديد', 'email' => 'new@example.com', 'password' => 'secret123']);
        Http::fake([self::STORE => Http::response(self::PAGE)]);
        $this->ai->replyWith($this->suggestion());

        $this->actingAs($newcomer)->postJson('/brand/profile/prefill', ['store_url' => self::STORE])
            ->assertOk()
            ->assertJsonPath('credits_charged', 0);

        $this->assertSame(0, Brand::count(), 'التعبئة لا تنشئ علامة؛ الحفظ هو الذي ينشئها');
    }

    public function test_internal_addresses_are_refused(): void
    {
        [$user] = $this->userWithBrand();
        Http::fake();

        $this->actingAs($user)->postJson('/brand/profile/prefill', ['store_url' => 'http://localhost/admin'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'داخلي'));

        Http::assertNothingSent();
    }

    public function test_the_form_offers_prefill_with_its_cost(): void
    {
        [$user] = $this->userWithBrand();

        $html = $this->actingAs($user)->get('/brand/profile')->assertOk()->getContent();

        $this->assertStringContainsString('عبّئ الأسئلة من الرابط', $html);
        $this->assertStringContainsString('brandAnswers(', $html);
        $this->assertStringContainsString('1 نقطة', $html);
    }
}
