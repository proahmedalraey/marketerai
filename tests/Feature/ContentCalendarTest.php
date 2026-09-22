<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\ScheduledPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «الخطة الشهرية» كتقويم نشر (2026-09-22): جدولة داخلية فقط — بلا نشر فعلي
 * على أي منصة بعد، فكل ما يُنشأ هنا صف تذكير محلي (mode=reminder).
 */
class ContentCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'سارة', 'email' => 'calendar@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'description' => 'مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي',
            'dialect' => 'saudi',
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    public function test_scheduling_new_text_to_two_platforms_creates_one_item_and_two_scheduled_posts(): void
    {
        $date = today()->addDays(2)->toDateString();

        $this->actingAs($this->user)->post('/content/schedule', [
            'source' => 'new',
            'caption' => 'جربوا قهوتنا الجديدة اليوم!',
            'platforms' => ['instagram', 'facebook'],
            'overrides' => ['instagram' => 'نص خاص بإنستغرام'],
            'mode' => 'schedule',
            'scheduled_date' => $date,
            'scheduled_time' => '10:30',
            'privacy' => 'public',
        ])->assertRedirect();

        $item = ContentItem::sole();
        $this->assertTrue($item->in_plan);
        $this->assertSame($date, $item->planned_for->toDateString());
        $this->assertSame(ContentStatus::Scheduled, $item->status);
        $this->assertSame('جربوا قهوتنا الجديدة اليوم!', $item->caption);

        $this->assertCount(2, $item->scheduledPosts);
        $this->assertSame(['instagram', 'facebook'], $item->scheduledPosts->pluck('platform')->all());
        $this->assertTrue($item->scheduledPosts->every(fn (ScheduledPost $p) => $p->status === PostStatus::Queued));
        $this->assertTrue($item->scheduledPosts->every(fn (ScheduledPost $p) => $p->mode === 'reminder'));
        $this->assertSame('نص خاص بإنستغرام', $item->scheduledPosts->firstWhere('platform', 'instagram')->caption_override);
        $this->assertNull($item->scheduledPosts->firstWhere('platform', 'facebook')->caption_override);
    }

    public function test_draft_mode_saves_nothing_to_the_calendar(): void
    {
        $this->actingAs($this->user)->post('/content/schedule', [
            'source' => 'new',
            'caption' => 'نص مسودة',
            'platforms' => ['instagram'],
            'mode' => 'draft',
        ])->assertRedirect();

        $item = ContentItem::sole();
        $this->assertFalse($item->in_plan);
        $this->assertNull($item->planned_for);
        $this->assertCount(0, $item->scheduledPosts);
    }

    public function test_scheduling_an_existing_draft_reuses_it_instead_of_duplicating(): void
    {
        $item = ContentItem::create([
            'brand_id' => $this->brand->id,
            'goal' => 'direct_sales',
            'platform' => 'instagram',
            'format' => 'post',
            'language' => 'ar',
            'body' => ['caption' => 'نص جاهز مسبقاً'],
            'caption' => 'نص جاهز مسبقاً',
            'status' => ContentStatus::Draft,
            'in_plan' => false,
        ]);

        $date = today()->addDay()->toDateString();

        $this->actingAs($this->user)->post('/content/schedule', [
            'source' => 'existing',
            'content_item_id' => $item->id,
            'platforms' => ['tiktok'],
            'mode' => 'schedule',
            'scheduled_date' => $date,
        ])->assertRedirect();

        $this->assertSame(1, ContentItem::count());
        $item->refresh();
        $this->assertTrue($item->in_plan);
        $this->assertSame($date, $item->planned_for->toDateString());
        $this->assertCount(1, $item->scheduledPosts);
        $this->assertSame('tiktok', $item->scheduledPosts->first()->platform);
    }

    public function test_rescheduling_replaces_the_previous_platforms_instead_of_stacking(): void
    {
        $item = ContentItem::create([
            'brand_id' => $this->brand->id,
            'goal' => 'direct_sales',
            'platform' => 'instagram',
            'format' => 'post',
            'language' => 'ar',
            'body' => ['caption' => 'نص'],
            'caption' => 'نص',
            'status' => ContentStatus::Draft,
            'in_plan' => false,
        ]);

        $post = fn (array $platforms) => $this->actingAs($this->user)->post('/content/schedule', [
            'source' => 'existing',
            'content_item_id' => $item->id,
            'platforms' => $platforms,
            'mode' => 'now',
        ]);

        $post(['instagram', 'facebook'])->assertRedirect();
        $this->assertCount(2, $item->fresh()->scheduledPosts);

        $post(['tiktok'])->assertRedirect();
        $item->refresh();
        $this->assertCount(1, $item->scheduledPosts);
        $this->assertSame('tiktok', $item->scheduledPosts->first()->platform);
    }

    public function test_platforms_are_required(): void
    {
        $this->actingAs($this->user)->post('/content/schedule', [
            'source' => 'new',
            'caption' => 'نص',
            'platforms' => [],
            'mode' => 'draft',
        ])->assertSessionHasErrors('platforms');
    }

    public function test_the_calendar_shows_items_scheduled_the_old_way_without_scheduled_posts(): void
    {
        $date = today()->addDays(3);

        ContentItem::create([
            'brand_id' => $this->brand->id,
            'goal' => 'direct_sales',
            'platform' => 'snapchat',
            'format' => 'post',
            'language' => 'ar',
            'body' => ['caption' => 'محتوى قديم بلا جدولة تفصيلية'],
            'caption' => 'محتوى قديم بلا جدولة تفصيلية',
            'status' => ContentStatus::Ready,
            'in_plan' => true,
            'planned_for' => $date,
        ]);

        $this->actingAs($this->user)
            ->get('/content/plan?date='.$date->toDateString())
            ->assertOk()
            ->assertSee('محتوى قديم بلا جدولة تفصيلية');
    }

    public function test_bulk_delete_removes_only_the_selected_items(): void
    {
        $keep = ContentItem::create([
            'brand_id' => $this->brand->id, 'goal' => 'direct_sales', 'platform' => 'instagram', 'format' => 'post',
            'language' => 'ar', 'body' => ['caption' => 'يبقى'], 'caption' => 'يبقى',
            'status' => ContentStatus::Ready, 'in_plan' => true, 'planned_for' => today()->addDay(),
        ]);

        $remove = ContentItem::create([
            'brand_id' => $this->brand->id, 'goal' => 'direct_sales', 'platform' => 'instagram', 'format' => 'post',
            'language' => 'ar', 'body' => ['caption' => 'يُحذف'], 'caption' => 'يُحذف',
            'status' => ContentStatus::Ready, 'in_plan' => true, 'planned_for' => today()->addDay(),
        ]);

        $this->actingAs($this->user)->post('/content/bulk-delete', ['ids' => [$remove->id]])
            ->assertRedirect();

        $this->assertModelExists($keep);
        $this->assertModelMissing($remove);
    }

    public function test_export_returns_a_csv_of_the_month(): void
    {
        ContentItem::create([
            'brand_id' => $this->brand->id, 'goal' => 'direct_sales', 'platform' => 'instagram', 'format' => 'post',
            'language' => 'ar', 'body' => ['caption' => 'محتوى للتصدير'], 'caption' => 'محتوى للتصدير',
            'status' => ContentStatus::Ready, 'in_plan' => true, 'planned_for' => today(),
        ]);

        $response = $this->actingAs($this->user)->get('/content/plan/export?month='.today()->format('Y-m'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('محتوى للتصدير', $response->streamedContent());
    }

    public function test_attaching_an_existing_media_asset_reparents_it_to_the_content_item(): void
    {
        $item = ContentItem::create([
            'brand_id' => $this->brand->id, 'goal' => 'direct_sales', 'platform' => 'instagram', 'format' => 'post',
            'language' => 'ar', 'body' => ['caption' => 'نص'], 'caption' => 'نص', 'status' => ContentStatus::Ready,
        ]);

        $asset = MediaAsset::create([
            'brand_id' => $this->brand->id, 'kind' => 'image', 'disk' => 'public', 'path' => 'gallery/test.png',
        ]);

        $this->actingAs($this->user)->post("/studio/{$item->id}/attach", ['media_asset_id' => $asset->id])
            ->assertRedirect();

        $this->assertSame($item->id, $asset->fresh()->content_item_id);
    }
}
