{{-- تقرير brand:eval-profile: طرفية ويندوز لا تعرض العربية من اليمين، فالمقارنة تُقرأ هنا --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تقييم ملف الهوية</title>
<style>
    :root { --bg: #f7f7f5; --card: #fff; --fg: #1c1c1a; --muted: #6b6b66; --line: #e4e4df; --err: #b42318; --warn: #a15c07; --ok: #067647; --soft: #f1f1ec; }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #151514; --card: #1e1e1c; --fg: #ececea; --muted: #a3a39d; --line: #33332f; --err: #f97066; --warn: #fdb022; --ok: #47cd89; --soft: #262623; }
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.9 "Segoe UI", Tahoma, "Noto Naskh Arabic", sans-serif; }
    main { max-width: 900px; margin: 0 auto; padding: 24px 16px 64px; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .meta { color: var(--muted); font-size: 14px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0 32px; background: var(--card); font-size: 14px; }
    th, td { border: 1px solid var(--line); padding: 6px 10px; text-align: right; }
    section { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 20px; margin-bottom: 24px; }
    section h2 { margin: 0; font-size: 19px; }
    h3 { font-size: 15px; margin: 18px 0 4px; color: var(--muted); }
    .text { white-space: pre-line; margin: 0; }
    .brief { background: var(--soft); border-radius: 8px; padding: 10px 14px; }
    .issues { list-style: none; padding: 0; margin: 12px 0 0; font-size: 14px; }
    .error { color: var(--err); } .warning { color: var(--warn); } .ok { color: var(--ok); }
    details { margin-top: 8px; font-size: 14px; }
    summary { cursor: pointer; color: var(--muted); }
    dl { margin: 8px 0 0; } dt { font-weight: 600; } dd { margin: 0 0 8px; white-space: pre-line; }
    code, .ltr { direction: ltr; unicode-bidi: isolate; }
</style>
</head>
<body>
<main>
    <h1>تقييم توليد ملف الهوية</h1>
    <p class="meta">{{ $generatedAt }}</p>

    <table>
        <tr><th>المشروع</th><th>النموذج</th><th>الدرجة</th><th>أخطاء</th><th>تحذيرات</th><th>الكلمات (مبسط · تفصيلي)</th><th>التحرير</th></tr>
        @foreach ($results as $r)
            <tr>
                <td>{{ $r['key'] }}</td>
                <td class="ltr">{{ $r['model'] ?? '—' }}</td>
                @if ($r['error'])
                    <td colspan="5" class="error">فشل الاستدعاء</td>
                @else
                    <td>{{ $r['score'] }}</td>
                    <td>{{ $r['errors'] }}</td>
                    <td>{{ $r['warnings'] }}</td>
                    <td>{{ $r['words']['simple'] }} · {{ $r['words']['detailed'] }}</td>
                    <td>{{ ($r['draft']['polished'] ?? false) ? 'نعم' : 'لا' }}</td>
                @endif
            </tr>
        @endforeach
    </table>

    @foreach ($results as $r)
        <section>
            <h2>{{ $r['name'] }} <span class="meta">({{ $r['key'] }})</span></h2>
            @if ($r['model'])
                <p class="meta"><span class="ltr">{{ $r['model'] }}</span> · {{ $r['latency_ms'] }}ms</p>
            @endif

            <details>
                <summary>إجابات صاحب المشروع (المدخلات)</summary>
                <dl>
                    @foreach ($r['answers'] as $question => $answer)
                        <dt>{{ $question }}</dt><dd>{{ $answer }}</dd>
                    @endforeach
                </dl>
            </details>

            @if ($r['error'])
                <p class="error">{{ $r['error'] }}</p>
            @else
                <ul class="issues">
                    @forelse ($r['issues'] as $issue)
                        <li class="{{ $issue['severity'] }}">[{{ $issue['field'] }}] {{ $issue['message'] }}</li>
                    @empty
                        <li class="ok">لا مشاكل.</li>
                    @endforelse
                </ul>

                <h3>الموجز الاستراتيجي</h3>
                <p class="text brief">{{ $r['draft']['brief'] ?? '—' }}</p>

                @if (filled($r['draft']['critique'] ?? null))
                    <h3>ملاحظات رئيس التحرير</h3>
                    <p class="text brief">{{ $r['draft']['critique'] }}</p>
                @endif

                <h3>الوصف المبسط</h3>
                <p class="text">{{ $r['draft']['simple'] }}</p>

                <h3>الوصف التفصيلي</h3>
                <p class="text">{{ $r['draft']['detailed'] }}</p>

                @if (! empty($r['draft']['before']))
                    <details>
                        <summary>المسودة قبل التحرير</summary>
                        <h3>المبسط</h3>
                        <p class="text">{{ $r['draft']['before']['simple'] }}</p>
                        <h3>التفصيلي</h3>
                        <p class="text">{{ $r['draft']['before']['detailed'] }}</p>
                    </details>
                @endif

                <h3>الوصف التقني</h3>
                <dl>
                    @foreach (\App\Models\BrandProfile::TECHNICAL_LABELS as $field => $label)
                        @if (filled($r['draft']['technical'][$field] ?? null))
                            <dt>{{ $label }}</dt><dd>{{ $r['draft']['technical'][$field] }}</dd>
                        @endif
                    @endforeach
                    <dt>الملاحظات المهمة</dt>
                    <dd>@foreach ((array) ($r['draft']['technical']['important_notes'] ?? []) as $i => $note){{ $i + 1 }}. {{ $note }}
@endforeach</dd>
                </dl>
            @endif
        </section>
    @endforeach
</main>
</body>
</html>
