<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use DispatchesJobs, ValidatesRequests, ApiResponse;

    protected function perPage(Request $request, int $default = 10, int $max = 200): int
    {
        $value = (int) $request->input('pageSize', $request->input('per_page', $default));
        return min(max($value, 1), $max);
    }
}
