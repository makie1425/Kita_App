<?php

namespace App\Http\Controllers;

use App\Services\InventoryAvailability;
use App\Services\InventoryRules;
use App\Services\ManagerApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ManagerWorkflowController extends Controller
{
    public function promotion(Request $r)
    {
        InventoryRules::authorize($r);
        $d = $r->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['Near-Expiry', 'Slow-Moving', 'Seasonal', 'Category-Wide'])],
            'occasionName' => ['nullable', 'string', 'max:120'],
            'category' => ['required_if:type,Category-Wide', 'nullable', Rule::exists('categories', 'name')->where('status', 'Active')->whereNull('archivedAt')],
            'discountPct' => ['required', 'numeric', 'gt:0', 'lt:100', 'decimal:0,2'],
            'startDate' => ['required', 'date_format:Y-m-d'], 'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'productIds' => ['required_unless:type,Category-Wide', 'array', 'max:1000'],
            'productIds.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
        ]);

        return DB::transaction(function () use ($r, $d) {
            InventoryRules::lockActor($r);
            $sequence = DB::table('inventory_sequences')->where('name', 'promotions')->lockForUpdate()->first();
            $products = DB::table('products')->when($d['type'] === 'Category-Wide', fn ($q) => $q->where('category', $d['category']), fn ($q) => $q->whereIn('id', $d['productIds']))->orderBy('id')->lockForUpdate()->get();
            abort_if($products->isEmpty(), 422, 'Select at least one product.');
            $overlaps = DB::table('promotions')->where('startDate', '<=', $d['endDate'])->where('endDate', '>=', $d['startDate'])->get();
            $links = DB::table('promotion_products')->whereIn('promotionId', $overlaps->pluck('id'))->get()->groupBy('promotionId');
            foreach ($products as $product) {
                InventoryRules::product($product->id);
                abort_if(round($product->price * (1 - $d['discountPct'] / 100), 2) < (float) $product->unitPrice, 422, 'Promotion would sell '.$product->name.' below cost.');
                foreach ($overlaps as $promo) {
                    abort_if(($promo->type === 'Category-Wide' && $promo->category === $product->category) || ($links->get($promo->id)?->contains('productId', $product->id) ?? false), 422, 'An overlapping promotion already applies to '.$product->name.'.');
                }
            }
            $id = max((int) $sequence->lastValue, (int) DB::table('promotions')->max('id')) + 1;
            $values = collect($d)->except('productIds')->all();
            $values['category'] = $d['type'] === 'Category-Wide' ? $d['category'] : null;
            DB::table('promotions')->insert(['id' => $id] + $values);
            DB::table('promotion_products')->insert($products->map(fn ($p) => ['promotionId' => $id, 'productId' => $p->id])->all());
            DB::table('inventory_sequences')->where('name', 'promotions')->update(['lastValue' => $id]);
            InventoryRules::audit($r->user()->name, 'Created promotion', (string) $id, null, json_encode($values));

            return response()->json(['message' => 'Promotion saved.', 'id' => $id], 201);
        }, 3);
    }

    public function expirySettings(Request $r)
    {
        InventoryRules::authorize($r);
        $d = $r->validate(['days' => ['required', 'integer', 'between:0,365']]);

        return DB::transaction(function () use ($r, $d) {
            InventoryRules::lockActor($r);
            $old = DB::table('inventory_settings')->where('name', 'expiry_days')->lockForUpdate()->value('value');
            DB::table('inventory_settings')->where('name', 'expiry_days')->update(['value' => $d['days']]);
            InventoryRules::audit($r->user()->name, 'Changed expiry alert window', 'expiry_days', (string) $old, (string) $d['days']);

            return response()->json(['message' => 'Expiry alert window saved.', 'days' => $d['days']]);
        });
    }

    public function evidence(Request $r, string $id)
    {
        InventoryRules::authorize($r);
        $e = DB::table('adjustment_evidence')->where('adjustmentId', $id)->first();
        abort_unless($e, 404);

        return response(base64_decode($e->content))->header('Content-Type', $e->mime)
            ->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'private, no-store');
    }

    public function contact(Request $r, string $id)
    {
        InventoryRules::authorize($r);
        $d = $r->validate(['requestKey' => ['required', 'uuid'], 'contactDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'person' => ['required', 'string', 'max:160'], 'channel' => ['required', Rule::in(['Phone', 'Email', 'In person', 'Message'])],
            'notes' => ['required', 'string', 'max:2000'], 'outcome' => ['required', 'string', 'max:2000']]);

        return DB::transaction(function () use ($r, $id, $d) {
            InventoryRules::lockActor($r);
            $receipt = DB::table('receiving_records')->where('id', $id)->lockForUpdate()->first();
            abort_unless($receipt, 404);
            abort_unless($receipt->discrepancy, 422, 'Select a receipt with a delivery discrepancy.');
            abort_if($d['contactDate'] < $receipt->date, 422, 'Contact date cannot precede receipt.');
            $previous = DB::table('supplier_contacts')->where('requestKey', $d['requestKey'])->first();
            if ($previous) {
                abort_unless($previous->receiptId === $id, 409);
                foreach ($d as $key => $value) {
                    abort_unless((string) $previous->$key === (string) $value, 409, 'Submission key already used.');
                }

                return response()->json(['message' => 'Contact already recorded.']);
            }
            $supplierId = DB::table('purchase_orders')->where('id', $receipt->poId)->value('supplierId');
            $contactId = DB::table('supplier_contacts')->insertGetId($d + ['receiptId' => $id, 'supplierId' => $supplierId, 'actor' => $r->user()->name, 'created_at' => now()]);
            DB::table('receiving_records')->where('id', $id)->update(['supplierContact' => $d['contactDate'].' '.$d['channel'].' - '.$d['person'].': '.$d['outcome']]);
            InventoryRules::audit($r->user()->name, 'Recorded supplier contact', $id, null, json_encode($d));

            return response()->json(['message' => 'Supplier contact recorded.', 'id' => $contactId], 201);
        }, 3);
    }

    public function trace(Request $r)
    {
        InventoryRules::authorize($r);
        $d = $r->validate(['batchId' => ['nullable', 'integer', 'exists:inventory_batches,id'], 'search' => ['required_without:batchId', 'nullable', 'string', 'max:40']]);
        $batches = DB::table('inventory_batches as b')->join('products as p', 'p.id', '=', 'b.productId')
            ->when(! empty($d['batchId']), fn ($q) => $q->where('b.id', $d['batchId']), fn ($q) => $q->where(fn ($q) => $q->where('b.batchNumber', $d['search'])->orWhere('b.lotNumber', $d['search'])))
            ->get(['b.*', 'p.name', 'p.category', 'p.status as productStatus', 'p.archivedAt as productArchivedAt']);
        $sales = DB::table('inventory_allocations as a')->join('transactions as t', 't.uuid', '=', 'a.referenceId')
            ->whereIn('a.batchId', $batches->pluck('id'))->whereIn('a.referenceType', ['checkout', 'payment_reservation', 'exchange_replacement'])
            ->orderByDesc('a.id')->get(['a.batchId', 'a.productId', 'a.quantity', 'a.returnedQuantity', 'a.referenceType', 't.uuid', 't.date', 't.status']);

        return response()->json(['batches' => InventoryAvailability::batches($batches), 'sales' => $sales])->header('Cache-Control', 'private, no-store');
    }

    public function declineOverride(Request $r)
    {
        InventoryRules::authorize($r, ['cashier', 'manager']);
        $d = $r->validate(['requestKey' => ['required', 'uuid'], 'manager_id' => ['required', 'integer'], 'manager_pin' => ['required', 'digits:4'],
            'reason' => ['required', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.productId' => ['required', 'integer', 'exists:products,id'], 'items.*.qty' => ['required', 'integer', 'between:1,1000000']]);
        $manager = ManagerApproval::verify($r, $d['manager_id'], $d['manager_pin']);

        return DB::transaction(function () use ($r, $d, $manager) {
            InventoryRules::lockActor($r);
            $previous = DB::table('override_decisions')->where('requestKey', $d['requestKey'])->first();
            if ($previous) {
                abort_unless($previous->requestedById === $r->user()->id && $previous->requestedByRole === $r->user()->role
                    && $previous->managerId === $manager->id && $previous->reason === $d['reason'] && $previous->items === json_encode($d['items']), 409);

                return response()->json(['message' => 'Override already declined.']);
            }
            $id = DB::table('override_decisions')->insertGetId(['requestKey' => $d['requestKey'], 'managerId' => $manager->id,
                'requestedById' => $r->user()->id, 'requestedByRole' => $r->user()->role, 'decision' => 'Declined',
                'reason' => $d['reason'], 'items' => json_encode($d['items']), 'created_at' => now()]);
            InventoryRules::audit($manager->name, 'Declined price override', (string) $id, null, json_encode(['items' => $d['items'], 'reason' => $d['reason']]));

            return response()->json(['message' => 'Price override declined. Original prices restored.'], 201);
        }, 3);
    }
}
