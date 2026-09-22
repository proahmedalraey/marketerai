<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Models\User;
use App\Services\Content\Proofreader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * صفحة «كتابة المحتوى» (2026-09-22): الشكل لكل منصة وحقله التابع، واللغة
 * واللهجة، والدفعة، والمسودة التي لا تدخل الخطة إلا بتاريخ.
 *
 * كل خيار في الواجهة يجب أن يصل إلى البرومبت أو المخطط؛ لا خيار يُعرض ثم يُتجاهل.
 */
class ContentWritingPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected Product $product;

    protected ScriptedAiManager $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = ScriptedAiManager::install();
        config(['ai.proofread.enabled' => false]);

        $this->user = User::create(['name' => 'ريم', 'email' => 'writer@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'description' => 'مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي',
            'dialect' => 'saudi',
            'credit_balance' => 20,
            'credits_allowance' => 20,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->product = Product::create([
            'brand_id' => $this->brand->id,
            'type' => 'good',
            'title' => 'سيروب المستكة',
            'features' => 'نكهة مستكة طبيعية',
            'specifications' => 'عبوة 1 لتر',
            'price' => 50,
            'currency' => 'SAR',
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    protected function item(array $input = []): array
    {
        return [
            'goal' => 'direct_sales',
            'platform' => 'instagram',
            'format' => 'image',
            'product_id' => $this->product->id,
            'language' => 'ar',
            'dialect' => 'saudi',
            ...$input,
        ];
    }

    protected function send(array ...$items): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post('/content/generator', [
            'mode' => count($items) > 1 ? 'batch' : 'single',
            'items' => $items,
        ]);
    }

    protected function reply(string $caption = 'سيروب المستكة بنكهة طبيعية.'): array
    {
        return ['caption' => $caption, 'hashtags' => ['قهوة']];
    }

    // ================================================================
    //  الشكل وحقله التابع
    // ================================================================

    public function test_a_format_the_platform_does_not_offer_is_refused_before_charging(): void
    {
        $this->send($this->item(['platform' => 'linkedin', 'format' => 'reels']))
            ->assertSessionHasErrors('items.0.format');

        $this->assertSame(0, GenerationJob::count());
        $this->assertSame(20, $this->brand->refresh()->credit_balance);
    }

    public function test_a_video_needs_its_duration(): void
    {
        $this->send($this->item(['format' => 'reels']))->assertSessionHasErrors('items.0.option');
        $this->send($this->item(['platform' => 'x', 'format' => 'thread', 'option' => '99']))->assertSessionHasErrors('items.0.option');

        $this->assertSame(0, GenerationJob::count());
    }

    public function test_arabic_needs_a_dialect_and_english_does_not(): void
    {
        $this->send($this->item(['dialect' => null]))->assertSessionHasErrors('items.0.dialect');

        $this->ai->replyWith(['caption' => 'Mastic syrup with a natural flavour.', 'hashtags' => ['coffee']]);
        $this->send($this->item(['language' => 'en', 'dialect' => null]))->assertSessionHasNoErrors();

        $request = $this->ai->requests[0];
        $this->assertStringContainsString('باللغة الإنجليزية', $request->system);
        $this->assertStringNotContainsString('تكتب بلهجة', $request->system);
        $this->assertStringContainsString('بالإنجليزية وبلا مسافات', $request->prompt);
        $this->assertSame('en', ContentItem::sole()->language);
        $this->assertNull(ContentItem::sole()->dialectLabel());
    }

    public function test_the_chosen_dialect_overrides_the_brand_dialect(): void
    {
        $this->ai->replyWith($this->reply());

        $this->send($this->item(['dialect' => 'lebanese']))->assertSessionHasNoErrors();

        $this->assertStringContainsString('بلهجة لبنانية', $this->ai->requests[0]->system);
        $this->assertSame('lebanese', ContentItem::sole()->options['dialect']);
        $this->assertSame('saudi', $this->brand->refresh()->dialect, 'اختيار المحتوى لا يغيّر لهجة العلامة');
    }

    public function test_a_reel_is_written_to_its_duration_with_a_filming_script(): void
    {
        $this->ai->replyWith([
            'hook' => 'قهوتك ناقصها شيء؟',
            'scenes' => [
                ['time' => '0-5', 'voiceover' => 'قهوتك ناقصها شيء؟ في 30 ثانية نوريك الحل.', 'on_screen' => 'ناقصها شيء؟', 'shot' => 'لقطة قريبة لكوب لاتيه على طاولة خشب.'],
                ['time' => '5-15', 'voiceover' => 'سيروب المستكة بنكهة مستكة طبيعية.', 'on_screen' => 'نكهة طبيعية', 'shot' => 'يد تسكب السيروب ببطء.'],
                ['time' => '15-30', 'voiceover' => 'اطلبه الحين.', 'on_screen' => 'اطلبه الحين', 'shot' => 'لقطة متوسطة للعبوة.'],
            ],
            'caption' => 'سيروب المستكة لقهوتك.',
            'hashtags' => ['قهوة'],
        ]);

        $this->send($this->item(['format' => 'reels', 'option' => '30', 'filming' => '1']))->assertSessionHasNoErrors();

        $request = $this->ai->requests[0];
        $this->assertStringContainsString('## شكل المحتوى', $request->prompt);
        $this->assertStringContainsString('المدة: 30 ثانية', $request->prompt);
        $this->assertStringContainsString('نحو 70 كلمة منطوقة', $request->prompt);
        $this->assertStringContainsString('سكريبت التصوير مطلوب', $request->prompt);

        $scenes = $request->schema['properties']['scenes'];
        $this->assertSame([4, 6], [$scenes['minItems'], $scenes['maxItems']]);
        $this->assertContains('shot', $scenes['items']['required']);

        $item = ContentItem::sole();
        $this->assertSame('reel', $item->format->value);
        $this->assertSame('reels', $item->variant);
        $this->assertSame('Reels', $item->variantLabel());
        $this->assertSame('30 ثانية', $item->optionLabel());
        $this->assertTrue($item->withFilming());
        $this->assertCount(3, $item->scenes());
        $this->assertSame('reel_script', $item->template);
        $this->assertSame(2, 20 - $this->brand->refresh()->credit_balance);

        // المدة التي اختارها التاجر حقيقة عن المحتوى: «30 ثانية» ليست رقماً مخترعاً
        $this->assertTrue($item->quality['passes'], json_encode($item->quality['issues'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    public function test_without_the_filming_option_no_shot_is_asked_for(): void
    {
        $this->ai->replyWith(['hook' => 'هوك', 'scenes' => [['time' => '0-5', 'voiceover' => 'سيروب المستكة.', 'on_screen' => '']], 'caption' => 'سيروب.', 'hashtags' => []]);

        $this->send($this->item(['platform' => 'snapchat', 'format' => 'single_snap', 'option' => '15']))->assertSessionHasNoErrors();

        $request = $this->ai->requests[0];
        $this->assertStringNotContainsString('سكريبت التصوير مطلوب', $request->prompt);
        $this->assertArrayNotHasKey('shot', $request->schema['properties']['scenes']['items']['properties']);
    }

    public function test_a_thread_is_a_list_of_tweets_each_within_the_limit(): void
    {
        $long = str_repeat('سيروب المستكة بنكهة طبيعية ', 12);

        $this->ai->replyWith(
            ['tweets' => ['سيروب المستكة بنكهة طبيعية.', $long, 'اطلبه الحين.', 'عبوة 1 لتر.'], 'hashtags' => ['قهوة']],
            ['tweets' => ['سيروب المستكة بنكهة طبيعية.', 'نكهة مستكة طبيعية.', 'اطلبه الحين.', 'عبوة 1 لتر.'], 'hashtags' => ['قهوة']],
        );

        $this->send($this->item(['platform' => 'x', 'format' => 'thread', 'option' => 'medium']))->assertSessionHasNoErrors();

        $first = $this->ai->requests[0];
        $this->assertSame([4, 6], [$first->schema['properties']['tweets']['minItems'], $first->schema['properties']['tweets']['maxItems']]);
        $this->assertStringContainsString('عدد التغريدات: من 4 إلى 6', $first->prompt);

        // التغريدة الطويلة خطأ في حقلها هي، فتُعاد للتصحيح
        $this->assertCount(2, $this->ai->requests);
        $this->assertStringContainsString('(التغريدة 2)', $this->ai->lastRequest()->prompt);

        $item = ContentItem::sole();
        $this->assertCount(4, $item->tweets());
        $this->assertStringContainsString("4/ عبوة 1 لتر.\n#قهوة", $item->copyText());
    }

    public function test_a_story_is_written_as_frames(): void
    {
        $this->ai->replyWith(['frames' => [
            ['text' => 'قهوتك ناقصها شيء؟', 'visual' => 'كوب لاتيه', 'interaction' => 'استفتاء: نعم / لا'],
            ['text' => 'سيروب المستكة', 'visual' => 'العبوة'],
        ]]);

        $this->send($this->item(['format' => 'story', 'option' => '30']))->assertSessionHasNoErrors();

        $this->assertStringContainsString('في 2 إلى 3 إطارات', $this->ai->requests[0]->prompt);

        $item = ContentItem::sole();
        $this->assertSame('story', $item->format->value);
        $this->assertCount(2, $item->frames());
        $this->assertSame('قهوتك ناقصها شيء؟', $item->previewText());
    }

    public function test_the_template_follows_the_goal_and_the_format(): void
    {
        $this->ai->replyWith($this->reply(), $this->reply());

        $this->send(
            $this->item(['goal' => 'followers']),
            $this->item(['platform' => 'linkedin', 'format' => 'career_story']),
        )->assertSessionHasNoErrors();

        $this->assertSame(['how_to_tip', 'career_story'], GenerationJob::orderBy('id')->get()->map(fn ($job) => $job->payload['template'])->all());
        $this->assertStringContainsString('لا تخترع أشخاصاً أو تواريخ', $this->ai->requests[1]->prompt);
    }

    public function test_general_content_leaves_the_primary_product_out(): void
    {
        $this->ai->replyWith($this->reply('مستلزمات المقاهي عندنا.'));

        $this->send($this->item(['product_id' => null, 'goal' => 'reputation']))->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('## المنتج', $this->ai->requests[0]->prompt);
        $this->assertNull(ContentItem::sole()->product_id);
    }

    // ================================================================
    //  الدفعة
    // ================================================================

    public function test_a_batch_is_one_job_per_setting_and_charged_per_format(): void
    {
        $this->ai->replyWith(
            $this->reply(),
            ['slides' => array_fill(0, 6, ['role' => 'pull', 'text' => 'سيروب المستكة بنكهة طبيعية.', 'visual' => 'syrup bottle']), 'caption' => 'سيروب.', 'hashtags' => []],
        );

        $this->send($this->item(), $this->item(['format' => 'carousel']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('batch_sent', true);

        $this->assertSame(2, GenerationJob::count());
        $this->assertSame(20 - 1 - 2, $this->brand->refresh()->credit_balance);
        $this->assertSame(['image', 'carousel'], ContentItem::orderBy('id')->pluck('variant')->all());
    }

    public function test_a_batch_the_balance_cannot_cover_is_refused_whole(): void
    {
        $this->brand->update(['credit_balance' => 3]);

        $this->send($this->item(['format' => 'carousel']), $this->item(['format' => 'carousel']))
            ->assertSessionHasErrors('credits');

        $this->assertSame(0, GenerationJob::count(), 'لا تُنفَّذ أول الدفعة وتتوقف في منتصفها');
        $this->assertSame(3, $this->brand->refresh()->credit_balance);
    }

    public function test_a_batch_has_a_ceiling(): void
    {
        $this->send(...array_fill(0, 11, $this->item()))->assertSessionHasErrors('items');

        $this->assertSame(0, GenerationJob::count());
    }

    // ================================================================
    //  المسودة ← الخطة الشهرية
    // ================================================================

    public function test_written_content_is_a_draft_on_the_writing_page_not_in_the_plan(): void
    {
        $this->ai->replyWith($this->reply('سيروب المستكة بنكهة مستكة طبيعية.'));
        $this->send($this->item())->assertSessionHasNoErrors();

        $item = ContentItem::sole();
        $this->assertFalse($item->in_plan);
        $this->assertSame('draft', $item->status->value);

        $this->actingAs($this->user)->get('/content/generator')
            ->assertOk()
            ->assertSee('محتوى جاهز للنشر')
            ->assertSee('سيروب المستكة بنكهة مستكة طبيعية.')
            ->assertSee('إضافة للخطة الشهرية')
            ->assertSee(today()->addDay()->toDateString());

        $this->actingAs($this->user)->get('/content/plan')->assertDontSee('سيروب المستكة بنكهة مستكة طبيعية.');
    }

    public function test_adding_to_the_plan_takes_the_chosen_date(): void
    {
        $this->ai->replyWith($this->reply('سيروب المستكة بنكهة مستكة طبيعية.'));
        $this->send($this->item());
        $item = ContentItem::sole();

        $this->actingAs($this->user)->post("/content/{$item->id}/add-to-plan", ['planned_for' => today()->subDay()->toDateString()])
            ->assertSessionHasErrors('planned_for');

        $date = today()->addDays(3)->toDateString();

        $this->actingAs($this->user)->post("/content/{$item->id}/add-to-plan", ['planned_for' => $date])
            ->assertRedirect(route('content.generator'));

        $item->refresh();
        $this->assertTrue($item->in_plan);
        $this->assertSame('ready', $item->status->value);
        $this->assertSame($date, $item->planned_for->toDateString());

        // الخطة الآن تقويم: النص يظهر عند اختيار يوم النشر تحديداً، لا في الصفحة المطلقة
        $this->actingAs($this->user)->get("/content/plan?date={$date}")->assertSee('سيروب المستكة بنكهة مستكة طبيعية.');
        $this->actingAs($this->user)->get('/content/generator')->assertDontSee('محتوى جاهز للنشر');
    }

    public function test_the_suggested_date_skips_days_already_planned(): void
    {
        ContentItem::create([
            'brand_id' => $this->brand->id, 'goal' => 'reach', 'platform' => 'instagram', 'format' => 'post',
            'body' => ['caption' => 'مخطط'], 'caption' => 'مخطط', 'status' => 'ready', 'in_plan' => true,
            'planned_for' => today()->addDay()->toDateString(),
        ]);

        $this->ai->replyWith($this->reply());
        $this->send($this->item());

        $this->actingAs($this->user)->get('/content/generator')
            ->assertSee('value="'.today()->addDays(2)->toDateString().'"', false);
    }

    public function test_retry_writes_a_new_version_with_the_same_settings_and_keeps_the_old(): void
    {
        $this->ai->replyWith(['hook' => 'هوك', 'scenes' => [['time' => '0-5', 'voiceover' => 'سيروب المستكة.', 'on_screen' => '']], 'caption' => 'سيروب.', 'hashtags' => []]);
        $this->send($this->item(['format' => 'reels', 'option' => '45', 'dialect' => 'emirati']));
        $first = ContentItem::sole();

        $this->ai->replyWith(['hook' => 'هوك آخر', 'scenes' => [['time' => '0-5', 'voiceover' => 'سيروب المستكة الحين.', 'on_screen' => '']], 'caption' => 'سيروب.', 'hashtags' => []]);

        $this->actingAs($this->user)->post("/content/{$first->id}/retry")
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, ContentItem::count());
        $retried = ContentItem::latest('id')->first();

        $this->assertSame(['reels', '45', 'emirati'], [$retried->variant, $retried->options['option'], $retried->options['dialect']]);
        $this->assertStringContainsString('بلهجة إماراتية', $this->ai->lastRequest()->system);
        $this->assertSame(20 - 2 - 2, $this->brand->refresh()->credit_balance);
    }

    public function test_deleting_a_draft_returns_to_the_writing_page(): void
    {
        $this->ai->replyWith($this->reply());
        $this->send($this->item());

        $this->actingAs($this->user)->delete('/content/'.ContentItem::sole()->id)
            ->assertRedirect(route('content.generator'));
    }

    public function test_english_content_skips_the_arabic_proofreader(): void
    {
        config(['ai.proofread.enabled' => true]);
        $this->ai->replyWith(['caption' => 'Mastic syrup with a natural flavour.', 'hashtags' => []]);

        $this->send($this->item(['language' => 'en', 'dialect' => null]))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->ai->requests);
        $this->assertNotSame(Proofreader::OPERATION, $this->ai->lastRequest()->operation);
    }

    // ================================================================
    //  العرض: كل شكل يُرسم في صفحة الكتابة وصفحة التعديل
    // ================================================================

    public function test_every_kind_of_draft_renders(): void
    {
        $bodies = [
            ['carousel', 'carousel', ['slides' => [['role' => 'hook', 'text' => 'شريحة أولى'], ['role' => 'ask', 'text' => 'شريحة أخيرة']], 'caption' => 'وصف الكاروسيل', 'hashtags' => ['قهوة']]],
            ['reel', 'reels', ['hook' => 'افتتاحية', 'scenes' => [['time' => '0-3', 'voiceover' => 'كلام المشهد', 'on_screen' => 'نص', 'shot' => 'لقطة قريبة']], 'caption' => 'وصف الفيديو', 'hashtags' => []]],
            ['reel', null, ['script' => 'سكربت قديم نصاً واحداً', 'caption' => 'ريل قديم', 'hashtags' => []]],
            ['story', 'multi_snaps', ['frames' => [['text' => 'إطار أول', 'visual' => 'صورة', 'interaction' => 'سؤال']], 'caption' => '', 'hashtags' => []]],
            ['thread', 'thread', ['tweets' => ['تغريدة أولى', 'تغريدة ثانية'], 'caption' => '', 'hashtags' => ['قهوة']]],
            ['infographic', 'infographic', ['title' => 'عنوان الإنفوجرافيك', 'sections' => [['heading' => 'قسم', 'text' => 'سطر']], 'caption' => 'وصف', 'hashtags' => []]],
            ['post', 'career_story', ['caption' => 'قصة مهنية', 'hashtags' => []]],
        ];

        foreach ($bodies as [$format, $variant, $body]) {
            $items[] = ContentItem::create([
                'brand_id' => $this->brand->id, 'product_id' => $this->product->id,
                'goal' => 'direct_sales', 'platform' => 'instagram', 'format' => $format, 'variant' => $variant,
                'options' => ['option' => '30', 'dialect' => 'saudi', 'filming' => true],
                'body' => $body, 'caption' => $body['caption'] ?? '', 'status' => 'draft', 'in_plan' => false,
            ]);
        }

        $html = $this->actingAs($this->user)->get('/content/generator')->assertOk()->getContent();

        foreach (['شريحة أولى', 'كلام المشهد', 'لقطة قريبة', 'سكربت قديم', 'إطار أول', 'تغريدة ثانية', 'عنوان الإنفوجرافيك', 'قصة مهنية', 'مع أسلوب التصوير'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        foreach ($items as $item) {
            $this->actingAs($this->user)->get("/content/{$item->id}")->assertOk()->assertSee('كتابة المحتوى');
        }
    }

    // ================================================================
    //  لوحة «إنتاجاتي»: متابعة العمليات من أي صفحة
    // ================================================================

    public function test_the_operations_panel_lists_jobs_on_every_page(): void
    {
        $this->ai->replyWith($this->reply('سيروب المستكة بنكهة مستكة طبيعية.'));
        $this->send($this->item(['format' => 'reels', 'option' => '30', 'filming' => '1']));

        GenerationJob::create([
            'brand_id' => $this->brand->id, 'type' => 'store_scan', 'status' => 'processing',
            'payload' => ['store_url' => 'https://example.com'],
        ]);

        // اللوحة في التخطيط، فهي على كل صفحة لا على صفحة الكتابة وحدها
        foreach (['/dashboard', '/products', '/content/plan'] as $uri) {
            $html = $this->actingAs($this->user)->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString('إنتاجاتي', $html);
            $this->assertStringContainsString('operationsCenter(', $html);

            foreach (GenerationJob::pluck('uuid') as $uuid) {
                $this->assertStringContainsString($uuid, $html, "المهمة {$uuid} غائبة عن اللوحة في {$uri}");
            }
        }
    }

    /** ما تعرضه اللوحة عن كل مهمة: عنوانها وتفاصيلها ومكان نتيجتها. */
    public function test_the_operations_panel_describes_a_content_job(): void
    {
        $this->ai->replyWith(['hook' => 'هوك', 'scenes' => [['time' => '0-5', 'voiceover' => 'سيروب المستكة.', 'on_screen' => '']], 'caption' => 'سيروب.', 'hashtags' => []]);
        $this->send($this->item(['format' => 'reels', 'option' => '30', 'filming' => '1']));

        $summary = (new \App\Support\JobSummary(GenerationJob::sole()->load('contentItems')))->toArray();

        $this->assertSame('كتابة محتوى', $summary['kind']);
        $this->assertSame('pen', $summary['icon']);
        $this->assertStringContainsString('زيادة المبيعات المباشرة · إنستغرام · Reels · 30 ثانية · سعودية · مع أسلوب التصوير', $summary['meta']);
        $this->assertSame(2, $summary['credits']);
    }

    public function test_a_finished_job_points_at_its_draft_and_a_running_one_does_not(): void
    {
        $this->ai->replyWith($this->reply());
        $this->send($this->item());

        $item = ContentItem::sole();
        $done = (new \App\Support\JobSummary(GenerationJob::sole()->load('contentItems')))->toArray();

        $this->assertSame('done', $done['state']);
        $this->assertSame(1, $done['succeeded']);
        $this->assertSame(route('content.generator', ['focus' => $item->id]), $done['resultUrl']);
        $this->assertSame(route('content.show', $item), $done['editUrl']);
        $this->assertStringContainsString('إنستغرام', $done['meta']);

        $running = (new \App\Support\JobSummary(GenerationJob::create([
            'brand_id' => $this->brand->id, 'type' => 'content', 'status' => 'queued',
            'payload' => ['variant' => 'carousel', 'platform' => 'tiktok', 'goal' => 'reach'],
        ])->load('contentItems')))->toArray();

        $this->assertSame('running', $running['state']);
        $this->assertNull($running['resultUrl'], 'لا نتائج قبل أن تنتهي');
        $this->assertSame('Carousel · تيك توك', $running['title']);
    }

    /** «عرض النتائج» يفتح الصفحة على تلك المسودة لا على الأحدث. */
    public function test_the_focus_link_opens_that_draft(): void
    {
        $this->ai->replyWith($this->reply('الأقدم في القائمة.'), $this->reply('الأحدث في القائمة.'));
        $this->send($this->item());
        $older = ContentItem::sole();
        $this->send($this->item());

        $this->actingAs($this->user)->get(route('content.generator', ['focus' => $older->id]))
            ->assertOk()
            ->assertSee('draftPager(2, 1)', false);

        $this->actingAs($this->user)->get('/content/generator')->assertSee('draftPager(2, 0)', false);
    }

    public function test_the_operations_panel_stays_inside_the_brand(): void
    {
        $other = Brand::create(['user_id' => $this->user->id, 'name' => 'علامة أخرى', 'onboarding_completed' => true]);

        GenerationJob::create([
            'brand_id' => $other->id, 'type' => 'store_scan', 'status' => 'completed',
            'payload' => ['store_url' => 'https://secret-store.test'],
        ]);

        $this->actingAs($this->user)->get('/dashboard')->assertOk()->assertDontSee('secret-store.test');
    }

    public function test_the_page_offers_every_platform_and_goal(): void
    {
        $html = $this->actingAs($this->user)->get('/content/generator')->assertOk()->getContent();

        foreach (['إنستغرام', 'تيك توك', 'تويتر (X)', 'فيسبوك', 'لينكد إن', 'يوتيوب', 'بينترست', 'سناب شات',
            'زيادة عدد الريتويت أو الريبوست', 'تعزيز السمعة', 'إظهار الميزة التنافسية الخاصة',
            'فصحى مبسطة', 'مغربية', 'إضافة سكريبت التصوير والمشهد', 'إضافة للدفعة'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('كتابة المحتوى', $html);
        $this->assertStringNotContainsString('مولّد المحتوى', $html);
    }
}
