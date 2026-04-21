<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\BasicAssignment;
use App\Models\Responsibility;
use App\Models\CoordinatorCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function getCoordinatorProgress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $startDate = \Carbon\Carbon::parse($request->start_date)->format('Y-m-d');
        $endDate   = \Carbon\Carbon::parse($request->end_date)->format('Y-m-d');

        $eventIds = Event::whereBetween('date', [$startDate, $endDate])->pluck('id');

        // Individual assignments (user_id is set)
        $individualAssignments = EventAssignment::whereIn('event_id', $eventIds)
            ->whereNotNull('user_id')
            ->get();

        // Team assignments (team_id is set)
        $teamAssignments = EventAssignment::whereIn('event_id', $eventIds)
            ->whereNotNull('team_id')
            ->get()
            ->groupBy('team_id');

        $individualUserIds = $individualAssignments->pluck('user_id');
        $assignedTeamIds   = $teamAssignments->keys();

        // Include users who are part of any team that has an assignment in this period
        $teamUserIds = User::where(function($q) use ($assignedTeamIds) {
            foreach ($assignedTeamIds as $tid) {
                $q->orWhereJsonContains('team_id', (int)$tid)
                  ->orWhereJsonContains('team_id', (string)$tid)
                  ->orWhere('team_id', $tid);
            }
        })
        ->whereNull('role_id')
        ->pluck('id');

        $userIds = $individualUserIds->merge($teamUserIds)->unique()->filter();

        $users = User::whereIn('id', $userIds)
            ->select('id', 'name', 'category_id', 'team_id')
            ->whereNull('role_id')
            ->get()
            ->keyBy('id');

        $categories = CoordinatorCategory::pluck('category_name', 'id');
        $report = [];

        foreach ($users as $user) {
            $userIndividualAssignments = $individualAssignments->where('user_id', $user->id);

            $totalItems = 0;
            $completed  = 0;

            // --- Individual checklist items ---
            foreach ($userIndividualAssignments as $assignment) {
                $checklist = $assignment->responsibility_checklist ?? [];
                if (is_array($checklist)) {
                    foreach ($checklist as $val) {
                        $totalItems++;
                        $completed += (int)$val === 1 ? 1 : 0;
                    }
                }
            }

            // --- Team checklist items ---
            $teamIdRaw = $user->team_id;
            if (!is_null($teamIdRaw) && $teamIdRaw !== '0' && $teamIdRaw !== '') {
                $userTeamIds = (is_string($teamIdRaw) && str_starts_with($teamIdRaw, '['))
                    ? json_decode($teamIdRaw, true)
                    : [(int)$teamIdRaw];

                foreach ($userTeamIds as $teamId) {
                    $teamRows = $teamAssignments->get($teamId, collect());
                    foreach ($teamRows as $assignment) {
                        $checklist = $assignment->responsibility_checklist ?? [];
                        if (is_array($checklist)) {
                            foreach ($checklist as $val) {
                                $totalItems++;
                                $status = is_array($val) ? (int)($val['status'] ?? 0) : (int)$val;
                                $completed += $status === 1 ? 1 : 0;
                            }
                        }
                    }
                }
            }

            $percentage = $totalItems > 0 ? round(($completed / $totalItems) * 100, 1) : 0;

            $catIds = $this->getUserCategoryIds($user);
            $categoryNames = collect($catIds)->map(fn($id) => $categories[$id] ?? null)->filter()->values();

            // --- Correctly calculate total events (Individual + Team) ---
            $individualEventIds = $userIndividualAssignments->pluck('event_id');
            $teamEventIds = collect();
            
            if (!is_null($teamIdRaw) && $teamIdRaw !== '0' && $teamIdRaw !== '') {
                foreach ($userTeamIds as $teamId) {
                    $teamEventIds = $teamEventIds->concat($teamAssignments->get($teamId, collect())->pluck('event_id'));
                }
            }
            $allParticipatedEventIds = $individualEventIds->concat($teamEventIds)->unique();

            $report[] = [
                'user_id'               => $user->id,
                'user_name'             => $user->name,
                'category_names'        => $categoryNames,
                'total_events'          => $allParticipatedEventIds->count(),
                'total_checklist_items' => $totalItems,
                'completed'             => $completed,
                'pending'               => $totalItems - $completed,
                'completion_percentage' => $percentage,
            ];
        }

        // Sort by completion percentage descending
        usort($report, fn($a, $b) => $b['completion_percentage'] <=> $a['completion_percentage']);

        return response()->json([
            'status' => 'success',
            'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            'total_events_in_range' => $eventIds->count(),
            'data'   => $report,
        ]);
    }

    /**
     * Get all users with their event assignments (Admin view).
     */
    public function getUsersWithAssignments()
    {
        $users = User::with(['eventAssignments' => function($q) {
            $q->with([
                'event:id,name,date,description,created_by', 
                'category:id,role_id,category_name', 
                'category.responsibilities:id,coordinator_id,role_id,name,level,period'
            ])->orderBy('id', 'desc')
            ->select('id', 'event_id', 'user_id', 'category_id', 'responsibility_checklist');
        }])
        // ->where('is_active', true)
        ->whereNotNull('category_id')
        ->whereNull('role_id')
        ->select('id', 'name', 'email', 'mobile_no', 'category_id', 'team_id', 'role_id', 'is_active')
        ->get();

        // Hydrate users with their human-readable category names
        $categories = CoordinatorCategory::pluck('category_name', 'id');
        $users->each(function ($user) use ($categories) {
            $ids = $this->getUserCategoryIds($user); // Use helper for robustness
            $user->category_names = collect($ids)->map(function ($id) use ($categories) {
                return $categories[$id] ?? null;
            })->filter()->values();
        });

        return response()->json([
            'status' => 'success',
            'data' => $users,
        ]);
    }

    /**
     * Get the authenticated user's Basic responsibilities (Level 1).
     */
    public function getMyBasicResponsibilities()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $categoryIds = $this->getUserCategoryIds($user);

        $responsibilities = Responsibility::whereIn('coordinator_id', $categoryIds)
            ->where('level', 1)
            ->with('category')
            ->get();

        // Fetch all basic assignments for this user
        $assignments = BasicAssignment::where('user_id', $user->id)->get();

        // Merge checklists from all records
        $mergedStatus = [];
        foreach ($assignments as $assignment) {
            $list = $assignment->responsibility_checklist ?? [];
            if (is_array($list)) {
                foreach ($list as $respId => $status) {
                    $mergedStatus[(string)$respId] = (int)$status;
                }
            }
        }

        // Map status into specific responsibility objects
        foreach ($responsibilities as $resp) {
            $resp->status = $mergedStatus[(string)$resp->id] ?? 0;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'responsibilities' => $responsibilities,
                'checklist_status' => (object)$mergedStatus,
            ]
        ]);
    }

    public function updateBasicChecklist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'checklist' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $categoryIds = $this->getUserCategoryIds($user);
        
        $updates = $request->checklist;
        $updatedCount = 0;

        foreach ($categoryIds as $catId) {
            $validRespIds = Responsibility::where('coordinator_id', $catId)
                ->where('level', 1)
                ->pluck('id')
                ->toArray();

            $assignment = BasicAssignment::firstOrCreate(
                ['user_id' => $user->id, 'category_id' => $catId],
                ['responsibility_checklist' => []]
            );

            $currentChecklist = $assignment->responsibility_checklist ?? [];
            
            foreach ($updates as $respId => $status) {
                if (in_array($respId, $validRespIds)) {
                    $currentChecklist[$respId] = (int)$status;
                    $updatedCount++;
                }
            }

            $assignment->responsibility_checklist = $currentChecklist;
            $assignment->updated_ip = $request->ip();
            $assignment->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => "Successfully updated $updatedCount basic responsibility statuses.",
            'data' => BasicAssignment::where('user_id', $user->id)->get(),
        ]);
    }

    /**
     * Get the authenticated user's Event-wise responsibilities (Level 2) —
     * includes both individual and team-shared assignments in one unified response.
     */
    public function getMyEventResponsibilities()
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        // --- 1. Individual assignments (user_id match) ---
        $individualAssignments = $user->eventAssignments()
            ->with(['event:id,name,date', 'category.responsibilities' => function($q) {
                $q->where('level', 2);
            }])
            ->orderBy('id', 'desc')
            ->get();

        $individualAssignments->each(function($assignment) {
            $assignment->is_team   = false;
            $assignment->team_name = null;
        });

        // --- 2. Team assignments (team_id match) ---
        $teamIdRaw = $user->team_id;
        $teamAssignments = collect();

        if (!is_null($teamIdRaw) && $teamIdRaw !== '0' && $teamIdRaw !== '') {
            $teamIds = (is_string($teamIdRaw) && str_starts_with($teamIdRaw, '['))
                ? json_decode($teamIdRaw, true)
                : [(int)$teamIdRaw];

            $teams = DB::table('master_teams')->whereIn('id', $teamIds)->pluck('team_name', 'id');

            $teamAssignments = EventAssignment::whereIn('team_id', $teamIds)
                ->with(['event:id,name,date', 'category.responsibilities' => function($q) {
                    $q->where('level', 2);
                }])
                ->orderBy('id', 'desc')
                ->get();

            $teamAssignments->each(function($assignment) use ($teams) {
                $assignment->is_team   = true;
                $assignment->team_name = $teams[$assignment->team_id] ?? null;
            });
        }

        $merged = $individualAssignments->merge($teamAssignments);

        // --- 3. Collect checker IDs from raw checklist data (avoids key-type mismatch) ---
        $checkerIds = [];
        foreach ($merged as $assignment) {
            $checklist = $assignment->responsibility_checklist ?? [];
            if ($assignment->is_team) {
                // Team format: {"resp_id": {"status": 0/1, "checked_by": user_id|null}}
                foreach ($checklist as $val) {
                    if (is_array($val) && !empty($val['checked_by'])) {
                        $checkerIds[] = (int)$val['checked_by'];
                    }
                }
            } else {
                // Individual: checker is the assigned user themselves (if any task is done)
                if (!is_null($assignment->user_id)) {
                    $checkerIds[] = (int)$assignment->user_id;
                }
            }
        }

        // Single DB call — keys cast to int to prevent int/string mismatch
        $checkerNames = User::whereIn('id', array_unique($checkerIds))
            ->pluck('name', 'id')
            ->mapWithKeys(fn($name, $id) => [(int)$id => $name]);

        // --- 4. Enrich responsibilities with status + resolved name in one pass ---
        foreach ($merged as $assignment) {
            if (!$assignment->category) continue;

            // Clone the category and its responsibilities to prevent shared reference overwriting
            $clonedCategory = clone $assignment->category;
            
            if ($clonedCategory->responsibilities) {
                $clonedResps = $clonedCategory->responsibilities->map(fn($r) => clone $r);
                
                $checklist = $assignment->responsibility_checklist ?? [];
                $isTeam    = $assignment->is_team;

                foreach ($clonedResps as $resp) {
                    $val = $checklist[$resp->id] ?? ($checklist[(string)$resp->id] ?? ($isTeam ? [] : 0));

                    if ($isTeam) {
                        $rawStatus        = is_array($val) ? (int)($val['status'] ?? 0) : (int)$val;
                        $rawCheckerId     = is_array($val) ? ($val['checked_by'] ?? null) : null;
                        $resp->status     = $rawStatus;
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

        return response()->json([
            'status' => 'success',
            'data'   => $merged->sortByDesc('id')->values(),
        ]);
    }

    /**
     * Helper to parse and return category IDs for a user.
     */
    private function getUserCategoryIds($user)
    {
        $categoryData = $user->category_id;
        if (is_array($categoryData)) {
            return $categoryData;
        } elseif (is_string($categoryData) && str_contains($categoryData, ',')) {
            return array_map('trim', explode(',', $categoryData));
        } else {
            return $categoryData ? [$categoryData] : [];
        }
    }

    public function updateEventChecklist(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'checklist' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $assignment = EventAssignment::find($id);

        if (!$assignment) {
            return response()->json(['status' => 'error', 'message' => 'Assignment not found.'], 404);
        }

        $user = auth()->user();

        // --- TEAM assignment: validate membership, store enriched JSON ---
        if (!is_null($assignment->team_id)) {
            $teamIdRaw   = $user->team_id;
            $userTeamIds = (is_string($teamIdRaw) && str_starts_with($teamIdRaw, '['))
                ? array_map('intval', json_decode($teamIdRaw, true))
                : [(int)$teamIdRaw];

            if (!in_array((int)$assignment->team_id, $userTeamIds)) {
                return response()->json(['status' => 'error', 'message' => 'You are not a member of this team.'], 403);
            }

            $currentChecklist = $assignment->responsibility_checklist ?? [];
            $updatedCount = 0;
            foreach ($request->checklist as $respId => $status) {
                if (array_key_exists((string)$respId, $currentChecklist) || array_key_exists($respId, $currentChecklist)) {
                    $currentChecklist[$respId] = [
                        'status'     => (int)$status,
                        'checked_by' => (int)$status === 1 ? $user->id : null,
                    ];
                    $updatedCount++;
                }
            }

            $assignment->responsibility_checklist = $currentChecklist;
            $assignment->updated_by = $user->id;
            $assignment->updated_ip = $request->ip();
            $assignment->save();

            return response()->json([
                'status'  => 'success',
                'message' => "Updated $updatedCount team checklist item(s).",
                'data'    => $assignment,
            ]);
        }

        // --- INDIVIDUAL assignment: original logic ---
        if ($assignment->user_id !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to this assignment.'], 403);
        }

        $currentChecklist = $assignment->responsibility_checklist;
        $updates = $request->checklist;

        $updatedCount = 0;
        foreach ($updates as $respId => $status) {
            if (isset($currentChecklist[$respId])) {
                $currentChecklist[$respId] = (int)$status;
                $updatedCount++;
            }
        }

        $assignment->responsibility_checklist = $currentChecklist;
        $assignment->updated_ip = $request->ip();
        $assignment->updated_by = auth()->id();
        $assignment->save();

        return response()->json([
            'status' => 'success',
            'message' => "Successfully updated $updatedCount responsibility statuses.",
            'data' => $assignment,
        ]);
    }

    public function storeUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'mobile_no'   => 'required|string|unique:users,mobile_no|max:15',
            'category_id' => 'required|array',
            'role_id'     => 'nullable|exists:master_roles,role_id',
            'team_id'     => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'name'        => $request->name,
                'mobile_no'   => $request->mobile_no,
                'category_id' => $request->category_id,
                'role_id'     => $request->role_id,
                'team_id'     => $request->team_id,
                'created_by'  => auth()->id(),
                'ip_address'  => $request->ip(),
                'is_active'   => true,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'User created successfully.',
                'data'    => $user
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create user: ' . $e->getMessage()
            ], 500);
        }
    }

    public function toggleUserStatus($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }

        $user->is_active = !$user->is_active;
        $user->updated_by = auth()->id();
        $user->ip_address = request()->ip();
        $user->save();

        $statusString = $user->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'status'  => 'success',
            'message' => "User account has been successfully $statusString.",
            'data'    => $user
        ]);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|required|string|max:255',
            'mobile_no'   => 'sometimes|required|string|max:15|unique:users,mobile_no,' . $id,
            'category_id' => 'sometimes|required|array',
            'role_id'     => 'sometimes|nullable|exists:master_roles,role_id',
            'team_id'     => 'sometimes|nullable|string|max:50',
            'is_active'   => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if ($request->has('category_id')) {
                $currentTenure = DB::table('tbl_tenure')->orderBy('id', 'desc')->offset(1)->first();
                
                DB::table('tbl_user_category_audit')->insert([
                    'user_id'     => $user->id,
                    'tenure_id'   => $currentTenure ? $currentTenure->id : DB::table('tbl_tenure')->orderBy('id', 'desc')->first()->id,
                    'category_id' => json_encode($user->category_id),
                    'updated_by'  => auth()->id(),
                    'updated_ip'  => $request->ip(),
                    'updated_at'  => now(),
                ]);
            }

            $user->update($request->only(['name', 'mobile_no', 'category_id', 'role_id', 'team_id', 'is_active']));
            
            $user->updated_by = auth()->id();
            $user->ip_address = $request->ip();
            $user->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'User updated successfully.',
                'data'    => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update user: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getTenureWiseReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenure_id'  => 'required|exists:tbl_tenure,id',
            'start_date' => 'sometimes|nullable|date',
            'end_date'   => 'sometimes|nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $tenureId = $request->tenure_id;
        $tenure   = DB::table('tbl_tenure')->find($tenureId);

        $eventQuery = Event::where('tenure_id', $tenureId);
        
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = \Carbon\Carbon::parse($request->start_date)->format('Y-m-d');
            $endDate   = \Carbon\Carbon::parse($request->end_date)->format('Y-m-d');
            $eventQuery->whereBetween('date', [$startDate, $endDate]);
        }
        
        $eventIds = $eventQuery->pluck('id');

        $individualAssignments = EventAssignment::whereIn('event_id', $eventIds)
            ->whereNotNull('user_id')
            ->get();

        $teamAssignments = EventAssignment::whereIn('event_id', $eventIds)
            ->whereNotNull('team_id')
            ->get()
            ->groupBy('team_id');

        $auditRecords = DB::table('tbl_user_category_audit as audit')
            ->join('users', 'audit.user_id', '=', 'users.id')
            ->where('audit.tenure_id', $tenureId)
            ->select('audit.user_id', 'audit.category_id', 'users.name', 'users.team_id')
            ->get();

        $categories = CoordinatorCategory::pluck('category_name', 'id');
        $report = [];

        foreach ($auditRecords as $audit) {
            $userIndividualAssignments = $individualAssignments->where('user_id', $audit->user_id);
            
            $totalItems = 0;
            $completed  = 0;

            // --- Individual checklist items ---
            foreach ($userIndividualAssignments as $assignment) {
                $checklist = $assignment->responsibility_checklist ?? [];
                if (is_array($checklist)) {
                    foreach ($checklist as $val) {
                        $totalItems++;
                        $completed += (int)$val === 1 ? 1 : 0;
                    }
                }
            }

            // --- Team checklist items ---
            $teamIdRaw = $audit->team_id;
            if (!is_null($teamIdRaw) && $teamIdRaw !== '0' && $teamIdRaw !== '') {
                $userTeamIds = (is_string($teamIdRaw) && str_starts_with($teamIdRaw, '['))
                    ? json_decode($teamIdRaw, true)
                    : [(int)$teamIdRaw];

                foreach ($userTeamIds as $teamId) {
                    $teamRows = $teamAssignments->get($teamId, collect());
                    foreach ($teamRows as $assignment) {
                        $checklist = $assignment->responsibility_checklist ?? [];
                        if (is_array($checklist)) {
                            foreach ($checklist as $val) {
                                $totalItems++;
                                $status = is_array($val) ? (int)($val['status'] ?? 0) : (int)$val;
                                $completed += $status === 1 ? 1 : 0;
                            }
                        }
                    }
                }
            }

            $percentage = $totalItems > 0 ? round(($completed / $totalItems) * 100, 1) : 0;

            // Categories from AUDIT (this is the key historical data)
            $catIds = json_decode($audit->category_id, true);
            if (!is_array($catIds)) {
                $catIds = $audit->category_id ? [$audit->category_id] : [];
            }
            $categoryNames = collect($catIds)->map(fn($id) => $categories[$id] ?? null)->filter()->values();

            $individualEventIds = $userIndividualAssignments->pluck('event_id');
            $teamEventIds = collect();
            if (!is_null($teamIdRaw) && $teamIdRaw !== '0' && $teamIdRaw !== '') {
                foreach ($userTeamIds as $teamId) {
                    $teamEventIds = $teamEventIds->concat($teamAssignments->get($teamId, collect())->pluck('event_id'));
                }
            }
            $allParticipatedEventIds = $individualEventIds->concat($teamEventIds)->unique();

            $report[] = [
                'user_id'               => $audit->user_id,
                'user_name'             => $audit->name,
                'tenure_name'           => $tenure->year . ' (' . $tenure->tenure . ')',
                'category_names'        => $categoryNames,
                'total_events'          => $allParticipatedEventIds->count(),
                'total_checklist_items' => $totalItems,
                'completed'             => $completed,
                'pending'               => $totalItems - $completed,
                'completion_percentage' => $percentage,
            ];
        }

        usort($report, fn($a, $b) => $b['completion_percentage'] <=> $a['completion_percentage']);

        return response()->json([
            'status' => 'success',
            'tenure' => $tenure->year . ' ' . $tenure->tenure,
            'total_events_in_tenure' => $eventIds->count(),
            'data'   => $report,
        ]);
    }
}
