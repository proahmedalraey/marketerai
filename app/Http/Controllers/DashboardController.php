<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\ScheduledPost;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $brand = $this->brand();

        $stats = [
            'products' => $brand->products()->where('is_active', true)->count(),
            'content' => ContentItem::count(),
            'images' => MediaAsset::where('kind', 'image')->count(),
            'scheduled' => ScheduledPost::where('status', 'queued')->count(),
            'credits' => (int) $brand->credit_balance,
            'allowance' => (int) $brand->credits_allowance,
        ];

        $recentContent = ContentItem::with('product')
            ->latest()
            ->take(6)
            ->get();

        $runningJobs = GenerationJob::whereIn('status', ['queued', 'processing'])
            ->latest()
            ->take(5)
            ->get();

        $readyCount = ContentItem::where('status', ContentStatus::Ready->value)->count();

        return view('dashboard.index', compact('brand', 'stats', 'recentContent', 'runningJobs', 'readyCount'));
    }
}
