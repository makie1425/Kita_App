<?php

namespace App\Http\Controllers;

use App\Services\CashierShift;
use App\Services\ManagerApproval;
use App\Services\SaleCheckout;
use App\Services\WorkspaceRead;
use App\Services\WorkspaceWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftController extends Controller
{
    public function current(Request $request)
    {
        WorkspaceWrite::requireId();
        $shift = CashierShift::active($request);
        return response()->json(['shift' => $shift, 'expectedCash' => $shift ? CashierShift::balance($shift) / 100 : null,
            'entries' => $shift ? WorkspaceRead::table('cashier_shift_entries')->where('shift_id', $shift->id)->orderBy('id')->get() : []])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request)
    {
        WorkspaceWrite::requireId();
        $data = $request->validate(['openingCash' => ['required','numeric','min:0','max:99999999.99','decimal:0,2']]);
        return DB::transaction(function () use ($request, $data) {
            WorkspaceRead::table('users')->where('id',$request->user()->id)->where('role',$request->user()->role)->lockForUpdate()->first();
            if (CashierShift::active($request, true)) throw ValidationException::withMessages(['shift' => 'You already have an open shift.']);
            $id = DB::table('cashier_shifts')->insertGetId(WorkspaceWrite::owned([
                'cashier_id' => $request->user()->id, 'cashier_role' => $request->user()->role,
                'cashier_name' => $request->user()->name, 'opening_cash' => $data['openingCash'], 'opened_at' => now(),
            ]));
            return response()->json(['shift' => WorkspaceRead::table('cashier_shifts')->where('id',$id)->first()], 201);
        });
    }

    public function move(Request $request)
    {
        WorkspaceWrite::requireId();
        $data = $request->validate(['type'=>['required','in:added,removed'], 'amount'=>['required','numeric','gt:0','max:99999999.99','decimal:0,2'],
            'reason'=>['required','string','max:255'], 'manager_id'=>['required','integer'], 'manager_pin'=>['required','digits:4']]);
        $manager = ManagerApproval::verify($request, $data['manager_id'], $data['manager_pin']);
        return DB::transaction(function () use ($request,$data,$manager) {
            $shift=CashierShift::requireActive($request);
            $amount=SaleCheckout::cents($data['amount']);
            if ($data['type']==='removed' && $amount>CashierShift::balance($shift)) throw ValidationException::withMessages(['amount'=>'Removal exceeds expected cash.']);
            CashierShift::record($shift,$data['type'],$amount,(string) \Illuminate\Support\Str::uuid(),$data['reason'],$manager->id);
            return $this->current($request);
        });
    }

    public function close(Request $request)
    {
        WorkspaceWrite::requireId();
        $data=$request->validate(['countedCash'=>['required','numeric','min:0','max:99999999.99','decimal:0,2'], 'differenceReason'=>['nullable','string','max:255']]);
        return DB::transaction(function () use ($request,$data) {
            $shift=CashierShift::requireActive($request);
            $expected=CashierShift::balance($shift); $counted=SaleCheckout::cents($data['countedCash']);
            if ($expected!==$counted && !trim($data['differenceReason']??'')) throw ValidationException::withMessages(['differenceReason'=>'Explain the cash difference before closing.']);
            WorkspaceRead::table('cashier_shifts')->where('id',$shift->id)->update(['closed_at'=>now(),'expected_cash'=>$expected/100,'counted_cash'=>$counted/100,'difference_reason'=>$data['differenceReason']??null]);
            return response()->json(['shift'=>WorkspaceRead::table('cashier_shifts')->where('id',$shift->id)->first()]);
        });
    }

    public function history(Request $request)
    {
        WorkspaceWrite::requireId();
        $query=WorkspaceRead::table('cashier_shifts')->orderByDesc('id')->limit(100);
        if ($request->user()->normalizedRole()==='cashier') $query->where('cashier_id',$request->user()->id)->where('cashier_role',$request->user()->role);
        return response()->json(['shifts'=>$query->get()])->header('Cache-Control','private, no-store');
    }

    public function report(Request $request, int $id)
    {
        WorkspaceWrite::requireId();
        $shift=WorkspaceRead::table('cashier_shifts')->where('id',$id)->first(); abort_unless($shift,404);
        if ($request->user()->normalizedRole()==='cashier') abort_unless($shift->cashier_id==$request->user()->id && $shift->cashier_role===$request->user()->role,403);
        $entries=WorkspaceRead::table('cashier_shift_entries')->where('shift_id',$id)->orderBy('id')->get();
        return response()->view('cashier-shift-report',compact('shift','entries'))->header('Cache-Control','private, no-store');
    }
}
