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
            'data' => Responsibility::with('category:id,role_id,category_name')->select('id', 'coordinator_id', 'name', 'level')->get(),
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

    public function getMyRoleResponsibilities()
    {
        $user = auth()->user();

        if (!$user || !$user->role_id) {
            return response()->json([
                'status' => 'success',
                'data' => (object)[]
            ]);
        }

        $responsibilities = Responsibility::where('role_id', $user->role_id)->get();

        // Get current tenure
        $now = \Carbon\Carbon::now();
        $year = $now->year;
        $month = $now->month;
        if ($month >= 4 && $month <= 9) {
            $tenureType = 'APR-SEP';
            $searchYear = $year;
        } else {
            $tenureType = 'OCT-MAR';
            $searchYear = ($month >= 1 && $month <= 3) ? $year - 1 : $year;
        }

        $tenure = \App\Models\Tenure::where('year', (string)$searchYear)
            ->where('tenure', $tenureType)
            ->first();

        if (!$tenure) {
            return response()->json(['status' => 'error', 'message' => 'Active tenure not found.'], 404);
        }

        // --- Fetch Checklists for each period ---
        
        // 1. Weekly: Use current week (Friday to Thursday)
        // Identification date is the Friday that started the current week.
        $referenceFriday = $now->isFriday() ? (clone $now) : (clone $now)->previous(\Carbon\Carbon::FRIDAY);
        $weekNum = $referenceFriday->weekOfYear;
        $weekYear = $referenceFriday->year;

        $weeklyAssignment = \App\Models\RoleAssignment::where('user_id', $user->id)
            ->where('role_id', $user->role_id)
            ->where('period', 1)
            ->where('week_number', $weekNum)
            ->where('year', $weekYear)
            ->first();

        // 2. Monthly: Use current month
        $monthlyAssignment = \App\Models\RoleAssignment::where('user_id', $user->id)
            ->where('role_id', $user->role_id)
            ->where('period', 2)
            ->where('month_number', $month)
            ->where('year', $year)
            ->first();

        // 3. Tenure: Use current tenure
        $tenureAssignment = \App\Models\RoleAssignment::where('user_id', $user->id)
            ->where('role_id', $user->role_id)
            ->where('period', 3)
            ->where('tenure_id', $tenure->id)
            ->first();

        $checklists = [
            1 => $weeklyAssignment ? ($weeklyAssignment->responsibility_checklist ?? []) : [],
            2 => $monthlyAssignment ? ($monthlyAssignment->responsibility_checklist ?? []) : [],
            3 => $tenureAssignment ? ($tenureAssignment->responsibility_checklist ?? []) : [],
        ];

        // Attach status to each responsibility
        foreach ($responsibilities as $resp) {
            $periodChecklist = $checklists[$resp->period] ?? [];
            $resp->status = (int)($periodChecklist[$resp->id] ?? 0);
        }

        $grouped = $responsibilities->groupBy('period');

        $periods = [
            1 => 'weekly',
            2 => 'monthly',
            3 => 'as_and_when_required'
        ];

        $formatted = [];
        foreach ($periods as $id => $name) {
            $formatted[$name] = $grouped->get($id) ?? [];
        }

        return response()->json([
            'status' => 'success',
            'data' => (object)$formatted,
        ]);
    }

    public function updateMyRoleResponsibilities(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'checklist' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $user = auth()->user();
        if (!$user || !$user->role_id) {
            return response()->json(['status' => 'error', 'message' => 'Role not found for user.'], 403);
        }

        // Get current tenure
        $now = \Carbon\Carbon::now();
        $year = $now->year;
        $month = $now->month;
        if ($month >= 4 && $month <= 9) {
            $tenureType = 'APR-SEP';
            $searchYear = $year;
        } else {
            $tenureType = 'OCT-MAR';
            $searchYear = ($month >= 1 && $month <= 3) ? $year - 1 : $year;
        }

        $tenure = \App\Models\Tenure::where('year', (string)$searchYear)
            ->where('tenure', $tenureType)
            ->first();

        if (!$tenure) {
            return response()->json(['status' => 'error', 'message' => 'Active tenure not found.'], 404);
        }

        // Group items by period
        $items = $request->checklist;
        $respIds = array_keys($items);
        $respInfo = Responsibility::whereIn('id', $respIds)->get(['id', 'period'])->keyBy('id');

        $groupedItems = [1 => [], 2 => [], 3 => []];
        foreach ($items as $id => $status) {
            $period = $respInfo[$id]->period ?? 3;
            $groupedItems[$period][(int)$id] = (int)$status;
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();
            $results = [];

            foreach ($groupedItems as $period => $checklist) {
                if (empty($checklist)) continue;

                $match = [
                    'user_id'   => $user->id,
                    'role_id'   => $user->role_id,
                    'tenure_id' => $tenure->id,
                    'period'    => $period,
                ];

                if ($period == 1) { // Weekly (Friday to Thursday)
                    $referenceFriday = $now->isFriday() ? (clone $now) : (clone $now)->previous(\Carbon\Carbon::FRIDAY);
                    $match['week_number'] = $referenceFriday->weekOfYear;
                    $match['year'] = $referenceFriday->year;
                } elseif ($period == 2) { // Monthly (1st of month)
                    $match['month_number'] = $month;
                    $match['year'] = $year;
                }

                $existing = \App\Models\RoleAssignment::where($match)->first();
                $finalChecklist = $checklist;
                if ($existing) {
                    $oldChecklist = $existing->responsibility_checklist ?? [];
                    // Use string keys for merge to avoid re-indexing if IDs are numeric but treated as keys
                    $finalChecklist = $checklist + $oldChecklist; 
                }

                $assignment = \App\Models\RoleAssignment::updateOrCreate(
                    $match,
                    [
                        'responsibility_checklist' => $finalChecklist,
                        'updated_ip'               => $request->ip(),
                        'created_ip'               => $existing ? $existing->created_ip : $request->ip(),
                    ]
                );
                $results[] = $assignment;
            }

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Role responsibilities updated successfully.',
                'data'    => $results
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update responsibilities: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getRoleAssignmentsReport(Request $request)
    {
        $currentUser = $request->user();
        if (!$currentUser || !in_array((int)$currentUser->role_id, [1, 2])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'tenure_id' => 'required|exists:tbl_tenure,id',
            'role_id'   => 'sometimes|nullable|exists:master_roles,role_id',
            'period'    => 'sometimes|nullable|integer|in:1,2,3',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $query = \App\Models\RoleAssignment::with('user:id,name', 'tenure:id,year,tenure')
            ->where('tenure_id', $request->tenure_id);

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }
        if ($request->filled('period')) {
            $query->where('period', $request->period);
        }
        if ($request->filled('week_number')) {
            $query->where('week_number', $request->week_number);
        }
        if ($request->filled('month_number')) {
            $query->where('month_number', $request->month_number);
        }
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        $assignments = $query->orderBy('updated_at', 'desc')->get();

        // Get all role responsibilities to map IDs to names
        $allResps = Responsibility::all(['id', 'name'])->keyBy('id');

        $report = $assignments->map(function($assignment) use ($allResps) {
            $checklist = $assignment->responsibility_checklist ?? [];
            $detailedChecklist = [];
            foreach ($checklist as $id => $status) {
                $resp = $allResps->get($id);
                $detailedChecklist[] = [
                    'responsibility_id' => $id,
                    'name'              => $resp ? $resp->name : 'Unknown',
                    'status'            => (int)$status
                ];
            }

            return [
                'id'                 => $assignment->id,
                'user_name'          => $assignment->user->name ?? 'Unknown',
                'role_id'            => $assignment->role_id,
                'tenure'             => $assignment->tenure ? $assignment->tenure->year . ' ' . $assignment->tenure->tenure : '',
                'period'             => (int)$assignment->period,
                'week_number'        => $assignment->week_number,
                'month_number'       => $assignment->month_number,
                'year'               => $assignment->year,
                'checklist'          => $detailedChecklist,
                'updated_at'         => $assignment->updated_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => $report
        ]);
    }
}
