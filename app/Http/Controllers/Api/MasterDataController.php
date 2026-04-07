<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoordinatorCategory;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    /**
     * Get all coordinator categories.
     */
    public function getCategories()
    {
        $categories = CoordinatorCategory::with('role')->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }
}
