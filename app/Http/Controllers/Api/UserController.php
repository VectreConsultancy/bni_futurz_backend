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
        $eventAssignments = EventAssignment::whereIn('event_id', $eventIds)->get();
        $userIds = $eventAssignments->pluck('user_id')->unique();

        $users = User::whereIn('id', $userIds)
            ->select('id', 'name', 'category_id')
            ->get()
            ->keyBy('id');

        $categories = CoordinatorCategory::pluck('category_name', 'id');
        $report = [];

        foreach ($users as $user) {
            $userAssignments = $eventAssignments->where('user_id', $user->id);

            $totalItems = 0;
            $completed  = 0;

            foreach ($userAssignments as $assignment) {
                $checklist = $assignment->responsibility_checklist ?? [];
                if (is_array($checklist)) {
                    $totalItems += count($checklist);
                    // $completed  += count(array_filter($checklist, fn($v) => (int)$v === 1));
                    $completed += array_sum($checklist);
                }
            }

            $percentage = $totalItems > 0 ? round(($completed / $totalItems) * 100, 1) : 0;

            $catIds = $this->getUserCategoryIds($user);
            $categoryNames = collect($catIds)->map(fn($id) => $categories[$id] ?? null)->filter()->values();

            $report[] = [
                'user_id'               => $user->id,
                'user_name'             => $user->name,
                'category_names'        => $categoryNames,
                'total_events'          => $userAssignments->pluck('event_id')->unique()->count(),
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
            ->where('is_active', true)
            ->whereNotNull('category_id')
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
     * Get the authenticated user's Event-wise responsibilities (Level 2).
     */
    public function getMyEventResponsibilities()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $assignments = $user->eventAssignments()
            ->with(['event', 'category.responsibilities' => function($q) {
                $q->where('level', 2);
            }])
            ->orderBy('id', 'desc')
            ->get();

        // Map status into each nested responsibility
        $assignments->each(function($assignment) {
            $checklist = $assignment->responsibility_checklist ?? [];
            if ($assignment->category && $assignment->category->responsibilities) {
                foreach ($assignment->category->responsibilities as $resp) {
                    $resp->status = $checklist[$resp->id] ?? 0;
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'data' => $assignments,
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

        if ($assignment->user_id !== auth()->id()) {
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
}
