<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $action, string $subject, array $details = [], ?int $actor = null): void
    {
        DB::table('audit_events')->insert([
            'user_id' => $actor ?? auth()->id(), 'action' => $action, 'subject' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
