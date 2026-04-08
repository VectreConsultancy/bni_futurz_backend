<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EventAssignment;
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

        return response()->json([
            'status' => 'success',
            'data' => $responsibilities,
        ]);
    }

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

    public function updateChecklist(Request $request, $id)
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
}
