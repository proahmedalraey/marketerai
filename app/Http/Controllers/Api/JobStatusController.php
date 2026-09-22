<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenerationJob;
use Illuminate\Http\JsonResponse;

class JobStatusController extends Controller
{
    /**
     * حالة مهمة للاستطلاع من الواجهة.
     * يعيد تقدماً حقيقياً للمهام المركبة مثل صور الكاروسيل.
     */
    public function show(GenerationJob $job): JsonResponse
    {
        abort_unless($job->brand_id === $this->brand()->id, 404);

        return response()->json([
            'uuid' => $job->uuid,
            'type' => $job->type,
            'status' => $job->status->value,
            'label' => $job->status->label(),
            'progress' => $job->progress(),
            'children_total' => $job->children_total,
            'children_done' => $job->children_done,
            'credits_charged' => $job->credits_charged,
            'error' => $job->error,
            'result' => $job->result,
        ]);
    }
}
