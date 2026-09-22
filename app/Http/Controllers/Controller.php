<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Support\CurrentBrand;

abstract class Controller
{
    protected function brand(): Brand
    {
        return CurrentBrand::get() ?? abort(403, 'لا يوجد براند نشط.');
    }
}
