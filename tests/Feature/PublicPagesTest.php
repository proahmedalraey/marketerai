<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function publicPages(): array
    {
        return [['/'], ['/about'], ['/contact'], ['/privacy'], ['/refund-policy'], ['/login'], ['/register']];
    }

    /** @dataProvider publicPages */
    public function test_public_pages_render_with_our_brand_only(string $uri): void
    {
        $html = $this->get($uri)->assertOk()->getContent();

        $this->assertStringContainsString('مسوق AI', $html);
        $this->assertStringNotContainsString('<x-', $html, "مكوّن لم يُحل في {$uri}");
        $this->assertStringNotContainsString('Sahal', $html, "بقي اسم المنافس في {$uri}");
        $this->assertStringNotContainsString('سهل AI', $html, "بقي اسم المنافس في {$uri}");
    }

    public function test_home_motion_sections_keep_their_content_in_the_html(): void
    {
        // الحركة تحسين فوق محتوى كامل: بلا سكربت (أو مع «تقليل الحركة») يبقى النص والصور والروابط
        $this->get('/')
            ->assertOk()
            ->assertSee('data-stories', false)
            ->assertSee('images/marketing/story-pour.jpg', false)
            ->assertSee('تبحث عن نكهة تميز قهوتك الحين؟')
            ->assertSee('<h1 data-reveal', false)
            ->assertSee('data-showcase', false)
            ->assertSee('images/marketing/tour-writer.webp', false)
            ->assertSee('data-assembly', false)
            ->assertSee('كلها في مسوق AI.')
            ->assertSee('data-timeline', false);
    }

    public function test_contact_links_never_lead_nowhere(): void
    {
        config(['marketing.contact.email' => null, 'marketing.contact.whatsapp' => null]);

        // بلا قنوات مضبوطة: كل جهة تقود إلى قسمها في صفحة التواصل
        $this->get('/')
            ->assertSee('تواصل معنا')
            ->assertSee(route('contact').'#sales', false)
            ->assertSee(route('contact').'#complaints', false);
        $this->get('/contact')->assertSee('قنوات التواصل قيد الإعداد');

        // واتساب مضبوط: الرابط يفتح المحادثة برسالة الجهة جاهزة
        config(['marketing.contact.whatsapp' => '+966 50 000 0000']);
        $this->get('/')->assertSee('https://wa.me/966500000000?text='.rawurlencode(config('marketing.channels.sales.message')), false);
    }

    public function test_social_icons_show_only_configured_accounts_to_visitors(): void
    {
        config(['marketing.social.instagram' => 'https://instagram.com/marketer.ai']);

        $this->get('/')
            ->assertSee('https://instagram.com/marketer.ai', false)
            ->assertDontSee('MARKETING_TIKTOK'); // المكان المُعلَّم للحساب الفارغ للبيئة المحلية فقط
    }

    public function test_faq_and_plans_are_compact_and_truthful(): void
    {
        $points = number_format(config('credits.monthly_allowance'));

        $this->get('/')
            ->assertSee('name="faq"', false)                     // فتح سؤال يغلق السابق
            ->assertSee("{$points} نقطة كل شهر — تكفي حتى")      // الرقم من config/credits.php
            ->assertSee('عرض كل المزايا (+5)')
            ->assertSee('فريق تسويق كامل… باشتراك واحد؟');
    }

    public function test_publish_threads_reach_the_platforms_we_write_for(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // ختام المشهد: المنصات الثماني من config/content.php، والخيوط تبدأ من «وانشر بثقة»
        $this->assertSame(count(config('content.platforms')), substr_count($html, 'data-assembly-platform'));
        $this->assertStringContainsString('data-assembly-publish-from', $html);
        foreach (config('content.platforms') as $platform) {
            $this->assertStringContainsString($platform['label'], $html);
        }

        // الشريط المتبدّل: أفعال قصيرة في الترويسة، وعبارات فائدة في التذييل (لا «مع مسوق AI» مكررة)
        $this->assertSame(2, substr_count($html, 'class="tool-ticker"'));
        foreach (config('marketing.tools') as $tool) {
            $this->assertStringContainsString($tool['verb'], $html);
            $this->assertStringContainsString($tool['phrase'], $html);
        }

        // شريط الترويسة يخفت مع اقتراب التذييل
        $this->assertStringContainsString("document.querySelector('footer')", $html);
    }

    public function test_home_sends_signed_in_users_to_the_dashboard(): void
    {
        $user = User::create(['name' => 'تاجر', 'email' => 'merchant@example.com', 'password' => 'secret123']);

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_prices_come_from_config_and_stay_left_to_right(): void
    {
        config(['marketing.offer.monthly_price' => 25, 'marketing.offer.yearly_price' => 240]);

        // العزل (LRI…PDI) يمنع النص العربي المحيط من قلب «$25» إلى «25$»
        $this->assertSame("\u{2066}\$25\u{2069}", Offer::monthly());
        $this->assertSame("\u{2066}\$20\u{2069}", Offer::yearlyPerMonth());
        $this->assertSame(20, Offer::yearlySaving());

        $this->get('/')->assertSee(Offer::monthly(), false)->assertSee(Offer::yearlySavingLabel(), false);
    }

    public function test_register_page_preselects_the_plan_from_the_link(): void
    {
        $this->get('/register?plan=yearly&trial=1')
            ->assertOk()
            ->assertSee("plan: 'yearly'", false)
            ->assertSee('trial: true', false);

        // قيمة غير معروفة ترجع للشهرية بدل أن تُحقن في Alpine
        $this->get("/register?plan=x'};alert(1)//")->assertSee("plan: 'monthly'", false);
    }

    public function test_registration_requires_terms_but_no_password_confirmation(): void
    {
        $this->post('/register', [
            'name' => 'تاجر', 'email' => 'new@example.com', 'password' => 'secret123',
        ])->assertSessionHasErrors('terms');

        $this->assertGuest();

        // الباقة والتجربة وكود الخصم تُرسل من النموذج ويتجاهلها الخادم حتى يُربط الدفع
        $this->post('/register', [
            'name' => 'تاجر', 'email' => 'new@example.com', 'password' => 'secret123', 'terms' => '1',
            'plan' => 'yearly', 'trial' => '1', 'coupon' => 'WELCOME',
        ])->assertRedirect(route('brand.profile'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }
}
