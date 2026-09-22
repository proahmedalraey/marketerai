<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\SpecSheetBuilder;
use App\Support\CurrentBrand;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@marketer.test'],
            ['name' => 'حساب تجريبي', 'password' => 'password']
        );

        $brand = Brand::firstOrCreate(
            ['slug' => 'demo-brand'],
            [
                'user_id' => $user->id,
                'name' => 'إمدادات القهوة',
                'industry' => 'توريد مستلزمات المقاهي والمشروبات',
                'description' => 'متجر سعودي يورّد القهوة والسيروبات وأدوات الباريستا للمقاهي والأفراد.',
                'audience' => 'أصحاب المقاهي والباريستا في السعودية، ومحضّرو المشروبات في البيت.',
                'tone' => 'خبير ودود وعملي، بلا مبالغة تسويقية',
                'dialect' => 'saudi',
                'selling_points' => ['توصيل سريع للمقاهي', 'أسعار جملة وتجزئة', 'منتجات مستوردة أصلية'],
                'colors' => [
                    ['hex' => '#6F4E37', 'name' => 'بني القهوة', 'role' => 'primary'],
                    ['hex' => '#C8A27A', 'name' => 'كراميل', 'role' => 'accent'],
                    ['hex' => '#FFFFFF', 'name' => 'أبيض', 'role' => 'background'],
                ],
                'visual_style' => 'تصوير دافئ بإضاءة طبيعية وخلفيات بسيطة',
                'onboarding_completed' => true,
            ]
        );

        $user->update(['current_brand_id' => $brand->id]);

        CurrentBrand::run($brand, function () use ($brand) {
            $specSheets = app(SpecSheetBuilder::class);

            $products = [
                [
                    'title' => 'سيروب المستكة اليوناني 1 لتر',
                    'summary' => 'سيروب مستكة طبيعي يوناني المنشأ بحجم 1 لتر، توازن دقيق في الحلاوة وقوام ثابت، يصلح للقهوة الساخنة والباردة والماتشا والآيس كريم.',
                    'price' => 50,
                    'category' => 'سيروبات',
                    'origin_country' => 'اليونان',
                    'is_primary' => true,
                ],
                [
                    'title' => 'حبيبات شوكولاتة بلجيكية 5 كجم',
                    'summary' => 'كيس شوكولاتة خام بلجيكية عالية الجودة بوزن 5 كجم، ذوبان حريري ونكهة غنية، مثالي للمخبوزات والحلويات الاحترافية.',
                    'price' => 320,
                    'category' => 'شوكولاتة',
                    'origin_country' => 'بلجيكا',
                ],
            ];

            foreach ($products as $data) {
                $product = Product::firstOrCreate(
                    ['brand_id' => $brand->id, 'title' => $data['title']],
                    $data + ['type' => 'good', 'currency' => 'SAR']
                );

                $product->update(['spec_sheet' => $specSheets->build($product)]);
            }
        });

        $this->command?->info('بيانات تجريبية جاهزة — الدخول: demo@marketer.test / password');
    }
}
