<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    private function owned(Request $request)
    {
        return DB::table('notifications')->where('recipientId', $request->user()->id)->where('recipientRole', $request->user()->role);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['before' => ['sometimes', 'integer', 'min:1']]);
        \App\Services\ExpiryMonitoring::notify($request->user());
        $query = $this->owned($request);
        if (isset($data['before'])) {
            $query->where('id', '<', $data['before']);
        }
        $items = $query->orderByDesc('id')->limit(51)->get(['id', 'type', 'message', 'priority', 'screen', 'recordId', 'created_at', 'read_at']);
        $more = $items->count() > 50;
        $items = $items->take(50)->values();

        return response()->json(['notifications' => $items, 'unread_count' => $this->owned($request)->whereNull('read_at')->count(),
            'next_before' => $more ? $items->last()->id : null])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $id)
    {
        abort_unless(ctype_digit($id), 404);
        $query = $this->owned($request)->where('id', $id);
        abort_unless($query->exists(), 404);
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function readAll(Request $request)
    {
        $this->owned($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
