<?php

namespace App\Services\Brand\Quality;

use App\Services\Quality\QualityReport;

/**
 * نتيجة فحص ملف هوية واحد. البنية مشتركة مع فحص المحتوى،
 * والاسم باقٍ لأن الملف والاختبارات وحقل quality تعرفه به.
 */
class ProfileQualityReport extends QualityReport {}
