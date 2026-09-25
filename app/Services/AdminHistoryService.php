<?php

namespace App\Services;

use App\Models\AdminHistory;
use App\Models\User;

class AdminHistoryService
{
    public function index(User $actor, array $filters)
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
        $q = AdminHistory::query();
        foreach (['admin_id', 'entite_id', 'entite', 'action'] as $field) {
            if (isset($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }
        if (isset($filters['du'])) {
            $q->whereDate('created_at', '>=', $filters['du']);
        }
        if (isset($filters['au'])) {
            $q->whereDate('created_at', '<=', $filters['au']);
        }

        return $q->orderByDesc('id')->paginate($filters['per_page'] ?? 50)->withQueryString();
    }
}
