<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Responsibility;
use App\Models\EventAssignment;
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
            'coordinator_id' => 'nullable|exists:master_coordinator_categories,id',
            // 'role_id'        => 'nullable|exists:master_roles,role_id',
            'name'           => 'required|string|max:500',
            'level'          => 'required|integer', // 1: Basic, 2: Event
            // 'period'         => 'nullable|integer', // 1: Weekly, 2: Monthly, 3: As Needed
            'event_id'       => 'nullable|exists:tbl_events,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $responsibility = Responsibility::create($request->only(['coordinator_id', 'name', 'level']));

        // If event_id is provided, append this new responsibility to existing checklists for that event & coordinator
        if ($request->filled('event_id') && $request->filled('coordinator_id')) {
            $eventId = $request->event_id;
            $coordId = $request->coordinator_id;

            // Find all assignments for this event and this specific coordinator category
            $assignments = EventAssignment::where('event_id', $eventId)
                ->where('category_id', $coordId)
                ->get();

            foreach ($assignments as $assignment) {
                $checklist = $assignment->responsibility_checklist ?? [];
                // Append the new ID with status 0
                $checklist[$responsibility->id] = 0;
                
                $assignment->responsibility_checklist = $checklist;
                $assignment->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Responsibility added successfully and synchronized with event assignments if applicable.',
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
            'coordinator_id' => 'sometimes|nullable|exists:master_coordinator_categories,id',
            // 'role_id'        => 'sometimes|nullable|exists:master_roles,role_id',
            'name'           => 'sometimes|required|string|max:500',
            // 'level'          => 'sometimes|required|integer',
            // 'period'         => 'sometimes|nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $responsibility->update($request->only(['coordinator_id', 'name', 'level']));

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
