<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\Credits\InsufficientCreditsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function brand(int $balance = 100): Brand
    {
        $user = User::create([
            'name' => 'اختبار', 'email' => 'test@example.com', 'password' => 'secret123',
        ]);

        return Brand::create([
            'user_id' => $user->id,
            'name' => 'براند اختبار',
            'credit_balance' => $balance,
            'credits_allowance' => 100,
        ]);
    }

    public function test_hold_deducts_immediately(): void
    {
        $brand = $this->brand(100);
        $credits = app(CreditService::class);

        $held = $credits->hold($brand, 'content.carousel');

        $this->assertEquals(2, $held);
        $this->assertEquals(98, $credits->balance($brand));
    }

    public function test_hold_fails_when_balance_is_short(): void
    {
        $brand = $this->brand(1);

        $this->expectException(InsufficientCreditsException::class);

        app(CreditService::class)->hold($brand, 'image.high_2k');
    }

    public function test_settle_refunds_the_unused_part(): void
    {
        $brand = $this->brand(100);
        $credits = app(CreditService::class);

        // حجزنا لست صور ونجحت أربع فقط
        $held = $credits->hold($brand, 'image.standard_1k', 6);
        $this->assertEquals(94, $credits->balance($brand));

        $credits->settle($brand, $held, 4, null, 'image.standard_1k');

        $this->assertEquals(96, $credits->balance($brand));
    }

    public function test_refund_returns_everything_on_failure(): void
    {
        $brand = $this->brand(50);
        $credits = app(CreditService::class);

        $held = $credits->hold($brand, 'content.carousel', 3);
        $credits->refund($brand, $held, null, 'content.carousel');

        $this->assertEquals(50, $credits->balance($brand));
    }
}
