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
        $events = Event::with(['assignments.user', 'assignments.category.responsibilities' => function($q) {
            $q->where('level', 2);
        }])->get();

        // Inject status into each responsibility within each assignment
        $events->each(function($event) {
            $event->assignments->each(function($assignment) {
                if ($assignment->category && $assignment->category->responsibilities) {
                    $checklist = $assignment->responsibility_checklist ?? [];
                    // Ensure checklist keys are handled correctly (string vs int keys in JSON)
                    foreach ($assignment->category->responsibilities as $resp) {
                        $resp->status = $checklist[$resp->id] ?? ($checklist[(string)$resp->id] ?? 0);
                    }
                }
            });
        });

        return response()->json([
            'status' => 'success',
            'data' => $events,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'             => 'required|string|max:255',
            'date'             => 'required|date',
            'description'      => 'nullable|string',
            'coordinator_ids'  => 'required|array',
            'coordinator_ids.*'=> 'exists:master_coordinator_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $event = Event::create([
                'name'       => $request->name,
                'date'       => $request->date,
                'description'=> $request->description,
                'created_by' => auth()->id(),
                'created_ip' => $request->ip(),
            ]);

            $requestedCoordIds = array_unique($request->coordinator_ids);

            // Load all teams keyed by their category_id for fast lookup
            $teamsByCategoryId = DB::table('master_teams')
                ->whereIn('category_id', $requestedCoordIds)
                ->get()
                ->keyBy('category_id');

            foreach ($requestedCoordIds as $catId) {
                $responsibilities = Responsibility::where('coordinator_id', $catId)
                    ->where('level', 2)
                    ->get();

                if ($responsibilities->isEmpty()) continue;

                // Check if this category belongs to a team
                $team = $teamsByCategoryId[$catId] ?? null;

                if ($team) {
                    // --- TEAM-BASED: ONE shared row with enriched JSON ---
                    $checklist = [];
                    foreach ($responsibilities as $resp) {
                        $checklist[$resp->id] = ['status' => 0, 'checked_by' => null];
                    }

                    EventAssignment::updateOrCreate(
                        [
                            'event_id'    => $event->id,
                            'team_id'     => $team->id,
                            'category_id' => $catId,
                        ],
                        [
                            'user_id'                 => null, // No specific user for team rows
                            'responsibility_checklist'=> $checklist,
                            'created_by'              => auth()->id(),
                            'created_ip'              => $request->ip(),
                        ]
                    );
                } else {
                    // --- INDIVIDUAL: one row per user (existing behaviour) ---
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
                                'event_id'    => $event->id,
                                'user_id'     => $user->id,
                                'category_id' => $catId,
                            ],
                            [
                                'team_id'                 => null,
                                'responsibility_checklist'=> $checklist,
                                'created_by'              => auth()->id(),
                                'created_ip'              => $request->ip(),
                            ]
                        );
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Event created and assignments auto-populated successfully.',
                'data'    => $event->load('assignments'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
