<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * معرض صور المنتج داخل نافذة التعديل:
 * عرض الموجود، حذف المحدد، واختيار المرجع البصري.
 */
class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::create([
            'name' => 'محمد', 'email' => 'gallery@example.com', 'password' => 'secret123',
        ]);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'industry' => 'توريد مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي',
            'dialect' => 'saudi',
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->product = Product::create([
            'brand_id' => $this->brand->id,
            'type' => 'good',
            'title' => 'سيروب المستكة',
            'features' => 'قوام ثابت',
            'is_active' => true,
        ]);

        foreach (range(0, 3) as $position) {
            $this->product->images()->create([
                'disk' => 'public',
                'path' => "brands/{$this->brand->id}/products/image-{$position}.jpg",
                'position' => $position,
                'is_reference' => $position === 0,
            ]);
        }
    }

    /** @return array<string, mixed> الحقول الدنيا المطلوبة لحفظ المنتج */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'good',
            'title' => $this->product->title,
            'features' => $this->product->features,
        ], $overrides);
    }

    public function test_the_edit_payload_carries_every_image(): void
    {
        $html = $this->actingAs($this->user)
            ->get('/products?edit='.$this->product->id)
            ->assertOk()
            ->getContent();

        // الصور تصل للواجهة عبر حمولة ألبين، وإلا فتح المعرض فارغاً
        $this->assertStringContainsString('image-0.jpg', $html);
        $this->assertStringContainsString('image-3.jpg', $html);
        $this->assertStringContainsString('is_reference', $html);
    }

    /**
     * الاختبارات الأخرى ترسل image_urls مباشرة، فتتجاوز النموذج ولا تكشف
     * غياب حقوله. هذا يتحقق من أن النافذة نفسها تحمل ما يصل للخادم.
     */
    public function test_the_modal_carries_every_field_the_server_reads(): void
    {
        $html = $this->actingAs($this->user)
            ->get('/products?edit='.$this->product->id)
            ->assertOk()
            ->getContent();

        foreach ([
            'name="images[]"' => 'رفع صورة من الجهاز',
            'name="image_urls[]"' => 'صور مستوردة من رابط',
            'name="removed_image_ids[]"' => 'حذف صورة',
            'name="reference"' => 'اختيار الصورة الرئيسية',
        ] as $field => $purpose) {
            $this->assertStringContainsString($field, $html, "حقل «{$purpose}» غائب عن النموذج");
        }
    }

    public function test_marked_images_are_deleted_with_their_files(): void
    {
        $doomed = $this->product->images()->orderBy('position')->get()->slice(1, 2);

        foreach ($doomed as $image) {
            Storage::disk('public')->put($image->path, 'x');
        }

        $this->actingAs($this->user)
            ->put("/products/{$this->product->id}", $this->payload([
                'removed_image_ids' => $doomed->pluck('id')->all(),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->product->images()->count());

        foreach ($doomed as $image) {
            $this->assertNull($this->product->images()->find($image->id));
            Storage::disk('public')->assertMissing($image->path);
        }
    }

    public function test_it_refuses_to_delete_an_image_belonging_to_another_product(): void
    {
        $other = Product::create([
            'brand_id' => $this->brand->id, 'type' => 'good',
            'title' => 'منتج آخر', 'features' => 'م', 'is_active' => true,
        ]);

        $victim = $other->images()->create([
            'disk' => 'public', 'path' => 'other.jpg', 'position' => 0, 'is_reference' => true,
        ]);

        $this->actingAs($this->user)
            ->put("/products/{$this->product->id}", $this->payload([
                'removed_image_ids' => [$victim->id],
            ]));

        $this->assertNotNull($other->images()->find($victim->id), 'حُذفت صورة منتج آخر');
        $this->assertSame(4, $this->product->images()->count());
    }

    public function test_choosing_an_existing_image_moves_the_reference(): void
    {
        $target = $this->product->images()->orderBy('position')->get()[2];

        $this->actingAs($this->user)
            ->put("/products/{$this->product->id}", $this->payload([
                'reference' => 'existing:'.$target->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->is_reference);
        $this->assertSame(1, $this->product->images()->where('is_reference', true)->count(),
            'المرجع البصري يجب أن يكون واحداً');
        $this->assertSame($target->id, $this->product->fresh()->referenceImage()->id);
    }

    public function test_a_newly_uploaded_image_can_be_chosen_as_the_reference(): void
    {
        $this->actingAs($this->user)
            ->put("/products/{$this->product->id}", $this->payload([
                'images' => [UploadedFile::fake()->image('new.jpg')],
                'reference' => 'new:0',
            ]))
            ->assertSessionHasNoErrors();

        $reference = $this->product->fresh()->referenceImage();

        $this->assertSame(5, $this->product->images()->count(), 'الصورة الجديدة تُضاف ولا تستبدل');
        $this->assertStringNotContainsString('image-', $reference->path, 'المرجع لم ينتقل للصورة الجديدة');
    }

    public function test_deleting_the_reference_promotes_another_image(): void
    {
        $reference = $this->product->images()->where('is_reference', true)->firstOrFail();

        $this->actingAs($this->user)
            ->put("/products/{$this->product->id}", $this->payload([
                'removed_image_ids' => [$reference->id],
                'reference' => 'existing:'.$reference->id,   // اختيار بائت لصورة حُذفت للتو
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->product->images()->where('is_reference', true)->count(),
            'المنتج بقي بلا مرجع بصري بعد حذف مرجعه');
        $this->assertNotNull($this->product->fresh()->referenceImage());
    }

    public function test_deleting_frees_room_for_a_new_upload_in_the_same_save(): void
    {
        // ست صور = الحد الأقصى؛ الرفع يجب أن ينجح لأن الحذف يسبقه
        foreach ([4, 5] as $position) {
            $this->product->images()->create([
                'disk' => 'public', 'path' => "full-{$position}.jpg",
                'position' => $position, 'is_reference' => false,
            ]);
        }

        $this->assertSame(6, $this->product->images()->count());

        $doomed = $this->product->images()->orderBy('position')->first();

        $this->actingAs($this->user)
            ->put("/products/{$this->product->id}", $this->payload([
                'removed_image_ids' => [$doomed->id],
                'images' => [UploadedFile::fake()->image('replacement.jpg')],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(6, $this->product->images()->count());
        $this->assertNull($this->product->images()->find($doomed->id));
    }

    public function test_saving_without_touching_images_keeps_the_reference(): void
    {
        $before = $this->product->images()->where('is_reference', true)->firstOrFail();

        // أكثر الحالات شيوعاً: يعدّل التاجر نصاً ويحفظ دون أن يمس الصور
        $this->actingAs($this->user)
            ->put("/products/{$this->product->id}", $this->payload([
                'features' => 'نص محرَّر',
                'reference' => 'existing:'.$before->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($before->fresh()->is_reference, 'ضاع المرجع البصري عند حفظ لا يغيّره');
        $this->assertSame(1, $this->product->images()->where('is_reference', true)->count());
    }

    public function test_an_imported_url_is_not_duplicated_and_can_be_the_reference(): void
    {
        $url = 'https://cdn.example.com/imported.jpg';

        foreach ([1, 2] as $_) {
            $this->actingAs($this->user)
                ->put("/products/{$this->product->id}", $this->payload([
                    'image_urls' => [$url],
                    'reference' => 'url:'.$url,
                ]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(1, $this->product->images()->where('original_url', $url)->count(),
            'الرابط نفسه تكرر عند الحفظ مرتين');
        $this->assertSame($url, $this->product->fresh()->referenceImage()->url());
    }
}
