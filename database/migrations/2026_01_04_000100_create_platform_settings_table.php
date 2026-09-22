<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات المنصة: قيم تُدار من الواجهة وتعلو على ملف البيئة.
 *
 * المفاتيح السرية (مفاتيح API) تُخزَّن مشفّرة بمفتاح التطبيق،
 * فتسريب قاعدة البيانات وحدها لا يكشفها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        // لا توجد أدوار بعد: أول حساب هو صاحب المنصة، وإلا بقيت الإعدادات بلا مدير
        if ($firstId = DB::table('users')->min('id')) {
            DB::table('users')->where('id', $firstId)->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('platform_settings');
    }
};
