<?php

namespace App\Http\Controllers;

use App\Models\MediaFolder;
use Illuminate\Http\Request;

/**
 * مجلدات معرض استوديو الصور (البند 2.2 في docs/image-studio-redesign-plan.md).
 * حذف مجلد لا يحذف صوره — folder_id يعود null (nullOnDelete)، فتظهر مجدداً
 * ضمن "الكل" لا تختفي.
 */
class MediaFolderController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ], [], ['name' => 'اسم المجلد']);

        $folder = MediaFolder::create([
            'brand_id' => $this->brand()->id,
            'name' => $data['name'],
        ]);

        return back()->with('status', "أُنشئ مجلد «{$folder->name}».");
    }

    public function destroy(MediaFolder $folder)
    {
        $folder->delete();

        return redirect()->route('studio.index')->with('status', 'حُذف المجلد. صوره عادت إلى "الكل".');
    }
}
