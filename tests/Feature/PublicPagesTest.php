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
        return [['/'], ['/about'], ['/privacy'], ['/refund-policy'], ['/login'], ['/register']];
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
