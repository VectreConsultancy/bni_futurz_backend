<?php
include 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\EventAssignment;
use App\Models\User;

$assignments = EventAssignment::all();
$stats = [];

foreach ($assignments as $assignment) {
    if (!$assignment->responsibility_checklist) continue;

    $checklist = $assignment->responsibility_checklist;
    $isTeam = !is_null($assignment->team_id);

    if ($isTeam) {
        // Team format: {"resp_id": {"status": 0/1, "checked_by": user_id|null}}
        foreach ($checklist as $item) {
            if (is_array($item) && ($item['status'] ?? 0) == 1 && !empty($item['checked_by'])) {
                $uid = $item['checked_by'];
                $stats[$uid] = ($stats[$uid] ?? 0) + 1;
            }
        }
    } else {
        // Individual format: {"resp_id": 0/1}
        if ($assignment->user_id) {
            $count = 0;
            foreach ($checklist as $val) {
                if ($val == 1) $count++;
            }
            if ($count > 0) {
                $stats[$assignment->user_id] = ($stats[$assignment->user_id] ?? 0) + $count;
            }
        }
    }
}

arsort($stats);
$rankings = [];
$rank = 1;
foreach (array_slice($stats, 0, 10, true) as $userId => $count) {
    $user = User::find($userId);
    $rankings[] = [
        'rank' => $rank++,
        'name' => $user ? $user->name : "User ID $userId",
        'checked_count' => $count
    ];
}

header('Content-Type: application/json');
echo json_encode($rankings, JSON_PRETTY_PRINT);
