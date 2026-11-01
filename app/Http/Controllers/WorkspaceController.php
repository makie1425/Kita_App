<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    private function assigned(Request $request)
    {
        $user = $request->user();
        return DB::table('workspace_memberships as m')->join('workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $user->id)->where('m.user_role', $user->normalizedRole())->where('m.active', true);
    }

    public function index(Request $request)
    {
        return response()->json(['current' => $request->user()->workspace_id,
            'workspaces' => $this->assigned($request)->get(['w.id', 'w.name'])])->header('Cache-Control', 'private, no-store');
    }

    public function select(Request $request)
    {
        abort_unless(config('workspaces.enabled'), 409, 'Workspace isolation is not enabled yet.');
        abort_unless(in_array($request->user()->normalizedRole(), ['admin', 'super_admin'], true), 403);
        $data = $request->validate(['workspace_id' => 'required|integer']);
        DB::transaction(function () use ($request, $data) {
            abort_unless($this->assigned($request)->where('w.id', $data['workspace_id'])->lockForUpdate()->first(), 404);
            $user = $request->user();
            $before = $user->workspace_id;
            $user->forceFill(['workspace_id' => $data['workspace_id']])->save();
            DB::table('audit_logs')->insert(['ts' => now(), 'user' => $user->name, 'action' => 'Selected workspace',
                'record' => 'Account '.$user->role.':'.$user->id, 'beforeValue' => (string) $before,
                'afterValue' => (string) $data['workspace_id'], 'workspace_id' => $data['workspace_id']]);
        });
        return response()->json(['message' => 'Workspace selected. Reload to load its records.']);
    }
}
