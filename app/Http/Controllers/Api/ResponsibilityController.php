<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Responsibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ResponsibilityController extends Controller
{

    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data' => Responsibility::with('category')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coordinator_id' => 'required|exists:master_coordinator_categories,id',
            'name' => 'required|string|max:1000',
            'level' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $responsibility = Responsibility::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Responsibility added successfully.',
            'data' => $responsibility,
        ], 201);
    }

    public function show($id)
    {
        $responsibility = Responsibility::with('category')->find($id);

        if (!$responsibility) {
            return response()->json(['status' => 'error', 'message' => 'Responsibility not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $responsibility,
        ]);
    }

    public function update(Request $request, $id)
    {
        $responsibility = Responsibility::find($id);

        if (!$responsibility) {
            return response()->json(['status' => 'error', 'message' => 'Responsibility not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'coordinator_id' => 'sometimes|required|exists:master_coordinator_categories,id',
            'name' => 'sometimes|required|string|max:1000',
            'level' => 'sometimes|required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $responsibility->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Responsibility updated successfully.',
            'data' => $responsibility,
        ]);
    }

    /**
     * Remove the specified responsibility from storage (Optional).
     */
    // public function destroy($id)
    // {
    //     $responsibility = Responsibility::find($id);

    //     if (!$responsibility) {
    //         return response()->json(['status' => 'error', 'message' => 'Responsibility not found.'], 404);
    //     }

    //     $responsibility->delete();

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Responsibility deleted successfully.',
    //     ]);
    // }
}
