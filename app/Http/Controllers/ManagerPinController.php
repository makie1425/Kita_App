<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ManagerPinController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user() && strtolower($request->user()->role) === 'manager', 403);
        $data = $request->validate(['pin' => ['required', 'digits:4', 'confirmed'], 'current_pin' => ['nullable', 'digits:4']]);
        $user = User::where('email', $request->user()->email)->whereRaw('LOWER(role) = ?', ['manager'])->firstOrFail();
        if ($user->approval_pin && ! $user->verifyApprovalPin($data['current_pin'] ?? '')) {
            throw ValidationException::withMessages(['current_pin' => 'The current PIN is incorrect.']);
        }
        DB::table('users')->where('email', $user->email)->whereRaw('LOWER(role) = ?', ['manager'])->update(['approval_pin' => Hash::make($data['pin'])]);

        return response()->json(['message' => 'Manager approval PIN saved.']);
    }
}
