<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\User;
use App\Models\Responsibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    public function index()
    {
        $events = Event::with('assignments.user', 'assignments.category')->get();

        return response()->json([
            'status' => 'success',
            'data' => $events,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $event = Event::create([
                'name' => $request->name,
                'date' => $request->date,
                'description' => $request->description,
                'created_by' => auth()->id(),
                'created_ip' => $request->ip(),
            ]);

            // Auto-assignment logic
            $users = User::where('is_active', true)->get();

            foreach ($users as $user) {
                // Get all category IDs for this user
                $categoryData = $user->category_id;
                
                if (is_array($categoryData)) {
                    $categoryIds = $categoryData;
                } elseif (is_string($categoryData) && str_contains($categoryData, ',')) {
                    // Handle comma-separated strings if any legacy data exists
                    $categoryIds = array_map('trim', explode(',', $categoryData));
                } else {
                    $categoryIds = $categoryData ? [$categoryData] : [];
                }

                // Prevent duplicate processing if same ID appears twice in the array
                $categoryIds = array_unique($categoryIds);

                foreach ($categoryIds as $catId) {
                    // Fetch responsibilities for this category
                    $responsibilities = Responsibility::where('coordinator_id', $catId)->get();

                    if ($responsibilities->isNotEmpty()) {
                        // Create responsibility checklist: { "id": 0, "id": 0 }
                        $checklist = [];
                        foreach ($responsibilities as $resp) {
                            $checklist[$resp->id] = 0;
                        }

                        EventAssignment::updateOrCreate(
                            [
                                'event_id' => $event->id,
                                'user_id' => $user->id,
                                'category_id' => $catId,
                            ],
                            [
                                'responsibility_checklist' => $checklist,
                                'created_by' => auth()->id(),
                                'created_ip' => $request->ip(),
                            ]
                        );
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Event created and assignments auto-populated successfully.',
                'data' => $event->load('assignments'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
