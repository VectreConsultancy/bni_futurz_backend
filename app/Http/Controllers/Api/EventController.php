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
            'coordinator_ids' => 'required|array',
            'coordinator_ids.*' => 'exists:master_coordinator_categories,id',
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

            $requestedCoordIds = array_unique($request->coordinator_ids);

            foreach ($requestedCoordIds as $catId) {
                $responsibilities = Responsibility::where('coordinator_id', $catId)
                    ->where('level', 2)
                    ->get();

                if ($responsibilities->isEmpty()) {
                    continue;
                }

                // Find users who have this category assigned
                // We use whereJsonContains because category_id is stored as a JSON array in users table
                $users = User::where('is_active', true)
                    ->where(function ($query) use ($catId) {
                        $query->whereJsonContains('category_id', (int)$catId)
                              ->orWhereJsonContains('category_id', (string)$catId);
                    })
                    ->get();

                foreach ($users as $user) {
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
