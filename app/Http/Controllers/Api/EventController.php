<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\User;
use App\Models\Responsibility;
use App\Models\Tenure;
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

        // Step 1: Collect all checker IDs directly from raw checklist data
        $checkerIds = [];
        foreach ($events as $event) {
            foreach ($event->assignments as $assignment) {
                $checklist = $assignment->responsibility_checklist ?? [];
                $isTeam    = !is_null($assignment->team_id);
                if ($isTeam && is_array($checklist)) {
                    foreach ($checklist as $val) {
                        if (is_array($val) && !empty($val['checked_by'])) {
                            $checkerIds[] = (int)$val['checked_by'];
                        }
                    }
                } elseif (!$isTeam) {
                    // For individual: the checker is the assigned user if any task is done
                    if (!is_null($assignment->user_id)) {
                        $checkerIds[] = (int)$assignment->user_id;
                    }
                }
            }
        }
        // Single DB call — cast key to int explicitly
        $checkerNames = User::whereIn('id', array_unique($checkerIds))
            ->pluck('name', 'id')
            ->mapWithKeys(fn($name, $id) => [(int)$id => $name]);

        // Step 2: Inject status & resolved name into each responsibility
        $events->each(function($event) use ($checkerNames) {
            $event->assignments->each(function($assignment) use ($checkerNames) {
                if ($assignment->category) {
                    // Clone the category and its responsibilities to prevent shared reference overwriting
                    $clonedCategory = clone $assignment->category;
                    if ($clonedCategory->responsibilities) {
                        $clonedResps = $clonedCategory->responsibilities->map(fn($r) => clone $r);
                        
                        $checklist = $assignment->responsibility_checklist ?? [];
                        $isTeam    = !is_null($assignment->team_id);
                        
                        foreach ($clonedResps as $resp) {
                            $val = $checklist[$resp->id] ?? ($checklist[(string)$resp->id] ?? ($isTeam ? [] : 0));
                            if ($isTeam) {
                                $rawStatus       = is_array($val) ? (int)($val['status'] ?? 0) : (int)$val;
                                $rawCheckerId    = is_array($val) ? ($val['checked_by'] ?? null) : null;
                                $resp->status    = $rawStatus;
                                $resp->checked_by = $rawCheckerId ? ($checkerNames[(int)$rawCheckerId] ?? null) : null;
                            } else {
                                $rawStatus        = is_array($val) ? (int)($val['status'] ?? 0) : (int)$val;
                                $resp->status     = $rawStatus;
                                $resp->checked_by = $rawStatus === 1
                                    ? ($checkerNames[(int)$assignment->user_id] ?? null)
                                    : null;
                            }
                        }
                        $clonedCategory->setRelation('responsibilities', $clonedResps);
                    }
                    $assignment->setRelation('category', $clonedCategory);
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
            'venue'            => 'nullable|string',
            'description'      => 'nullable|string',
            'coordinator_ids'  => 'required|array',
            'coordinator_ids.*'=> 'exists:master_coordinator_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // Get currently running tenure
            $tenure = Tenure::latest('id')->first();

            $event = Event::create([
                'name'       => $request->name,
                'date'       => $request->date,
                'venue'      => $request->venue,
                'tenure_id'  => $tenure ? $tenure->id : null,
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
                // Get Level 2 responsibilities
                $responsibilities = Responsibility::where('coordinator_id', $catId)
                    ->where('level', 2)
                    ->get();

                // Check if this category belongs to a team
                $team = $teamsByCategoryId[$catId] ?? null;

                if ($team) {
                    // --- TEAM-BASED: ONE shared row with enriched JSON ---
                    $checklist = [];
                    foreach ($responsibilities as $resp) {
                        $checklist[$resp->id] = ['status' => 0, 'checked_by' => null];
                    }

                    // Only insert if tasks exist for this category
                    if (!empty($checklist)) {
                        EventAssignment::updateOrCreate(
                            [
                                'event_id'    => $event->id,
                                'team_id'     => $team->id,
                                'category_id' => $catId,
                            ],
                            [
                                'user_id'                 => null, 
                                'responsibility_checklist'=> $checklist,
                                'created_by'              => auth()->id(),
                                'created_ip'              => $request->ip(),
                            ]
                        );
                    }
                } else {
                    // --- INDIVIDUAL: one row per user ---
                    // Better query for JSON category_id
                    $users = User::where('is_active', true)
                        ->where(function ($query) use ($catId) {
                            $query->whereJsonContains('category_id', (int)$catId)
                                  ->orWhereJsonContains('category_id', (string)$catId)
                                  ->orWhere('category_id', $catId);
                        })
                        ->get();

                    foreach ($users as $user) {
                        $checklist = [];
                        foreach ($responsibilities as $resp) {
                            $checklist[$resp->id] = 0;
                        }

                        // Only insert if tasks exist for this category
                        if (!empty($checklist)) {
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

    public function storeTenure(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year'   => 'required|string',
            'tenure' => 'required|in:APR-SEP,OCT-MAR',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $tenure = Tenure::create([
            'year'       => $request->year,
            'tenure'     => $request->tenure,
            'created_by' => auth()->id(),
            'created_ip' => $request->ip(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Tenure created successfully.',
            'data'    => $tenure,
        ], 201);
    }

    public function getTenures()
    {
        $tenures = Tenure::orderBy('id', 'desc')->get();
        return response()->json([
            'status' => 'success',
            'data'   => $tenures,
        ]);
    }
}
