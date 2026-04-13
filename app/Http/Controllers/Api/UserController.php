<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EventAssignment;
use App\Models\BasicAssignment;
use App\Models\Responsibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Get all users with their event assignments (Admin view).
     */
    public function getUsersWithAssignments()
    {
        $users = User::with(['eventAssignments' => function($q) {
                $q->with(['event', 'category.responsibilities'])->orderBy('id', 'desc');
            }])
            ->where('is_active', true)
            ->get();

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
            $user->update($request->only(['name', 'mobile_no', 'category_id', 'team_id', 'is_active']));
            
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
