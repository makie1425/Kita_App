<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PDO;
use App\Services\WorkspaceRead;
use Throwable;

class KitaDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            if (request()->user()->normalizedRole() === 'super_admin') {
                $admins = DB::table('users')->where('role', 'admin');
                if (config('workspaces.enabled')) {
                    $workspaceId = WorkspaceRead::id();
                    $admins->where('workspace_id', $workspaceId ?: -1);
                }
                return response()->json([
                    'BUSINESS_DATE' => now()->toDateString(),
                    'USERS' => ['cashier' => [], 'manager' => [], 'admin' => $admins->orderBy('id')->get(['id','name','username','email','status','createdBy'])->toArray(), 'superadmin' => []],
                    'NOTIFICATIONS' => \App\Services\NotificationAccess::owned(request()->user())->orderByDesc('id')->limit(50)->get(['id','type','priority','message'])->toArray(),
                ] + array_fill_keys(['CATEGORIES','PRODUCTS','EXPIRING_BATCHES','BRANDS','SUBCATEGORIES','SUPPLIERS','ITEM_REQUESTS','PURCHASE_ORDERS','RECEIVING_RECORDS','ADJUSTMENTS','PROMOTIONS','AUDIT_LOGS','FIELD_VERSION_HISTORY','SALES_LOG','TRANSACTIONS'], []) + ['EXPIRY_ALERT_DAYS' => 7, 'BATCH_RECALL' => ['batch' => '', 'lot' => '', 'product' => '', 'affected' => []]])->header('Cache-Control', 'private, no-store');
            }
            $pdo = DB::connection()->getPdo();

            $payload = [
                'BUSINESS_DATE' => now()->toDateString(),
                'CATEGORIES' => $this->rows($pdo, ['table'=>'categories','columns'=>['name','status','classification','archivedAt','archivedBy'],'order'=>'created_at DESC, name']),
                'PRODUCTS' => $this->products($pdo),
                'EXPIRING_BATCHES' => \App\Services\ExpiryMonitoring::batches(),
                'EXPIRY_ALERT_DAYS' => \App\Services\ExpiryMonitoring::days(),
                'BRANDS' => WorkspaceRead::table('brands')->orderByDesc('id')->get(),
                'SUBCATEGORIES' => WorkspaceRead::table('subcategories')->orderByDesc('id')->get(),
                'SUPPLIERS' => $this->suppliers($pdo),
                'ITEM_REQUESTS' => $this->itemRequests($pdo),
                'PURCHASE_ORDERS' => $this->purchaseOrders($pdo),
                'RECEIVING_RECORDS' => $this->receivingRecords($pdo),
                'ADJUSTMENTS' => $this->adjustments($pdo),
                'PROMOTIONS' => $this->promotions($pdo),
                'USERS' => $this->users($pdo),
                'AUDIT_LOGS' => $this->auditLogs($pdo),
                'FIELD_VERSION_HISTORY' => $this->rows($pdo, ['table'=>'field_version_history','columns'=>['field','record','oldValue','newValue','user','ts','reason'],'order'=>'ts DESC, id DESC']),
                'NOTIFICATIONS' => \App\Services\NotificationAccess::owned(request()->user())->orderByDesc('id')->limit(50)->get(['id', 'type', 'priority', 'message'])->toArray(),
                'SALES_LOG' => $this->numericRows($pdo, ['table'=>'sales_log','columns'=>['date','hour','productId','qty','amount'],'order'=>'date, hour, id'], ['hour', 'productId', 'qty', 'amount']),
                'TRANSACTIONS' => $this->transactions($pdo),
                'BATCH_RECALL' => $this->batchRecall($pdo),
            ];
            if(config('workspaces.enabled') && request()->user()->normalizedRole()==='cashier'){
                foreach(['SUPPLIERS','ITEM_REQUESTS','PURCHASE_ORDERS','RECEIVING_RECORDS','ADJUSTMENTS','AUDIT_LOGS','FIELD_VERSION_HISTORY','SALES_LOG','EXPIRING_BATCHES'] as $key)$payload[$key]=[];
                $payload['BATCH_RECALL']=['batch'=>'','lot'=>'','product'=>'','affected'=>[]];
            }
            return response()->json($payload)->header('Cache-Control','private, no-store');
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Database connection/query failed.',
                'message' => 'The requested data could not be loaded. Please try again.',
            ], 500);
        }
    }

    private function rows(PDO $pdo, array $spec): array
    {
        $query=WorkspaceRead::table($spec['table']);
        foreach(explode(',', $spec['order']) as $order){
            $parts=preg_split('/\s+/',trim($order));$query->orderBy($parts[0],$parts[1]??'asc');
        }
        return $query->get($spec['columns'])->map(fn($row)=>(array)$row)->all();
    }

    private function numericRows(PDO $pdo, array $spec, array $fields): array
    {
        return array_map(fn(array $row):array=>$this->numericRow($row,$fields),$this->rows($pdo,$spec));
    }

    private function numericRow(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && is_numeric($row[$field])) {
                $row[$field] += 0;
            }
        }

        return $row;
    }

    private function jsonValue(mixed $value, mixed $fallback): mixed
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    private function products(PDO $pdo): array
    {
        return \App\Services\InventoryAvailability::products($this->numericRows(
            $pdo,
            ['table'=>'products','columns'=>['manufacturer','brandId','subcategoryId','size','sizeUnit','id','name','category','vatClass','price','cost','unitPrice','registrationQuantity','stock','minStock','unit','status','batch','lot','expiry','barcode','supplierId','parentId','variantLabel','purchaseUnit','stockUnit','conversionFactor','barcodeStatus','archivedAt','archivedBy'],'order'=>'created_at DESC, id DESC'],
            ['id', 'price', 'cost', 'unitPrice', 'registrationQuantity', 'stock', 'minStock', 'supplierId', 'parentId', 'conversionFactor']
        ));
    }

    private function suppliers(PDO $pdo): array
    {
        $suppliers = array_map(function (array $row): array {
            $row = $this->numericRow($row, ['id']);
            $row['callbackLog'] = $this->jsonValue($row['callbackLog'], []);
            $row['products'] = [];

            return $row;
        }, $this->rows($pdo, ['table'=>'suppliers','columns'=>['id','name','status','contact','phone','email','address','category','notes','archivedAt','archivedBy','callbackLog'],'order'=>'id DESC']));

        $index = [];
        foreach ($suppliers as $key => $supplier) {
            $index[(int) $supplier['id']] = $key;
        }

        foreach ($this->rows($pdo, ['table'=>'supplier_products','columns'=>['supplierId','productId','costPrice','preferred'],'order'=>'supplierId, productId']) as $product) {
            $supplierId = (int) $product['supplierId'];
            if (! array_key_exists($supplierId, $index)) {
                continue;
            }

            $suppliers[$index[$supplierId]]['products'][] = [
                'productId' => (int) $product['productId'],
                'costPrice' => $product['costPrice'] === null ? null : $product['costPrice'] + 0,
                'preferred' => (bool) $product['preferred'],
            ];
        }

        return $suppliers;
    }

    private function itemRequests(PDO $pdo): array
    {
        $requests = $this->rows($pdo, ['table'=>'item_requests','columns'=>['id','category','status','dateRequested','requestedBy','adminNote','disapprovalReason','poId'],'order'=>'dateRequested DESC, id DESC']);
        $index = [];

        foreach ($requests as $key => $request) {
            $requests[$key]['lines'] = [];
            $index[$request['id']] = $key;
        }

        foreach ($this->rows($pdo, ['table'=>'item_request_lines','columns'=>['requestId','productId','name','qty','supplierId','confirmedQty','editedQty'],'order'=>'id']) as $line) {
            $requestId = $line['requestId'];
            if (! array_key_exists($requestId, $index)) {
                continue;
            }

            unset($line['requestId']);
            $requests[$index[$requestId]]['lines'][] = $this->numericRow($line, ['productId', 'qty', 'supplierId', 'confirmedQty', 'editedQty']);
        }

        return $requests;
    }

    private function purchaseOrders(PDO $pdo): array
    {
        $orders = array_map(function (array $row): array {
            $row = $this->numericRow($row, ['supplierId', 'orderedValue', 'deliveredValue', 'invoicedValue', 'paidValue', 'outstandingValue', 'cancelledValue', 'confirmedQty']);
            $row['supplierCallback'] = $this->jsonValue($row['supplierCallback'], null);
            $row['receivingRecordIds'] = $this->jsonValue($row['receivingRecordIds'], []);
            $row['lines'] = [];

            return $row;
        }, $this->rows($pdo, ['table'=>'purchase_orders','columns'=>['id','itemRequestId','supplierId','status','supplierCallback','orderedValue','deliveredValue','invoicedValue','paidValue','outstandingValue','cancelledValue','receivingRecordIds','created','confirmedQty'],'order'=>'created DESC, id DESC']));

        $index = [];
        foreach ($orders as $key => $order) {
            $index[$order['id']] = $key;
        }

        foreach ($this->rows($pdo, ['table'=>'purchase_order_lines','columns'=>['poId','productId','name','orderedQty','unitCost','lineTotal','deliveredQty','outstandingAction'],'order'=>'id']) as $line) {
            $poId = $line['poId'];
            if (! array_key_exists($poId, $index)) {
                continue;
            }

            unset($line['poId']);
            $orders[$index[$poId]]['lines'][] = $this->numericRow($line, ['productId', 'orderedQty', 'unitCost', 'lineTotal', 'deliveredQty']);
        }

        return $orders;
    }

    private function receivingRecords(PDO $pdo): array
    {
        $records = array_map(function (array $row): array {
            $row['discrepancy'] = (bool) $row['discrepancy'];
            $row['lines'] = [];

            return $row;
        }, $this->rows($pdo, ['table'=>'receiving_records','columns'=>['id','poId','date','deliveryStatus','discrepancy','discrepancyType','supplierContact','outcome','adminApproval','barcodeAssignment'],'order'=>'date DESC, id DESC']));

        $index = [];
        foreach ($records as $key => $record) {
            $index[$record['id']] = $key;
        }

        foreach ($this->rows($pdo, ['table'=>'receiving_record_lines','columns'=>['receivingId','productId','poQty','deliveredQty','invoiceQty','conditionText'],'order'=>'id']) as $line) {
            $receivingId = $line['receivingId'];
            if (! array_key_exists($receivingId, $index)) {
                continue;
            }

            $records[$index[$receivingId]]['lines'][] = [
                'productId' => (int) $line['productId'],
                'poQty' => (int) $line['poQty'],
                'deliveredQty' => (int) $line['deliveredQty'],
                'invoiceQty' => (int) $line['invoiceQty'],
                'condition' => $line['conditionText'],
            ];
        }

        return $records;
    }

    private function adjustments(PDO $pdo): array
    {
        return array_map(function (array $row): array {
            $row = $this->numericRow($row, ['productId', 'qtyChange']);
            $row['photo'] = (bool) $row['photo'];

            return $row;
        }, $this->rows($pdo, ['table'=>'adjustments','columns'=>['id','productId','qtyChange','reason','status','photo','comment','remarks','date'],'order'=>'date DESC, id DESC']));
    }

    private function promotions(PDO $pdo): array
    {
        $promotions = $this->numericRows($pdo, ['table'=>'promotions','columns'=>['id','name','type','occasionName','discountPct','startDate','endDate','category'],'order'=>'id DESC'], ['id', 'discountPct']);
        $index = [];

        foreach ($promotions as $key => $promotion) {
            $promotions[$key]['productIds'] = [];
            $index[(int) $promotion['id']] = $key;
        }

        foreach ($this->rows($pdo, ['table'=>'promotion_products','columns'=>['promotionId','productId'],'order'=>'promotionId, productId']) as $product) {
            $promotionId = (int) $product['promotionId'];
            if (array_key_exists($promotionId, $index)) {
                $promotions[$index[$promotionId]]['productIds'][] = (int) $product['productId'];
            }
        }

        return $promotions;
    }

    private function users(PDO $pdo): array
    {
        $users = ['cashier' => [], 'manager' => [], 'admin' => [], 'superadmin' => []];

        foreach (WorkspaceRead::table('users')->orderBy('role')->orderBy('id')->get(['role','id','name','username','email','status','refundLimit','schedule','createdBy']) as $record) {
            $user = (array) $record;
            $role = (new User(['role' => $user['role']]))->normalizedRole();
            if ($role === 'super_admin') {
                $role = 'superadmin';
            }
            unset($user['role']);
            $users[$role] ??= [];
            $users[$role][] = $this->numericRow($user, ['id', 'refundLimit']);
        }

        if (request()->user()->normalizedRole() === 'cashier' && ($workspaceId = WorkspaceRead::id())) {
            $managerId = WorkspaceRead::managerId($workspaceId);
            if ($managerId) {
                $users['manager'] = DB::table('users')->where('id', $managerId)
                    ->where('role', 'manager')->where('workspace_id', $workspaceId)
                    ->whereRaw("LOWER(COALESCE(status, 'Active')) = 'active'")
                    ->get(['id', 'name', 'status'])->map(fn ($manager) => [
                        'id' => (int) $manager->id, 'name' => $manager->name, 'status' => $manager->status,
                    ])->all();
            }
        }

        return $users;
    }

    private function auditLogs(PDO $pdo): array
    {
        if (config('workspaces.enabled') && request()->user()->normalizedRole() === 'super_admin') return [];
        return array_map(fn (array $row): array => [
            'ts' => $row['ts'],
            'user' => $row['user'],
            'action' => $row['action'],
            'record' => $row['record'],
            'before' => $row['beforeValue'],
            'after' => $row['afterValue'],
        ], $this->rows($pdo, ['table'=>'audit_logs','columns'=>['ts','user','action','record','beforeValue','afterValue'],'order'=>'ts DESC, id DESC']));
    }

    private function transactions(PDO $pdo): array
    {
        $lines = WorkspaceRead::table('transaction_lines')->orderBy('id')->get()->groupBy('transaction_uuid');

        // UUIDs are random. The first sale-line ID records checkout insertion order,
        // including payments created within the same second, without renumbering sales.
        return WorkspaceRead::table('transactions')->orderByDesc('date')
            ->orderByDesc(WorkspaceRead::table('transaction_lines')->selectRaw('MIN(id)')
                ->whereColumn('transaction_uuid', 'transactions.uuid'))
            ->orderBy('uuid')->get()->map(function ($record) use ($lines) {
                $row = (array) $record;
                $row = $this->numericRow($row, ['total', 'subtotal', 'discountAmount', 'refundedAmount', 'tendered', 'paid', 'changeAmount']);
                $row['change'] = $row['changeAmount'];
                $row['lines'] = ($lines->get($record->uuid) ?? collect())->map(fn ($line) => $this->numericRow((array) $line, ['productId', 'qty', 'unitPrice', 'discountAmount', 'lineTotal', 'refundedQty', 'refundedAmount']))->all();

                return $row;
            })->all();
    }

    private function batchRecall(PDO $pdo): array
    {
        $rows = $this->rows($pdo, ['table'=>'batch_recall_affected','columns'=>['batch','lot','product','location','qty','note'],'order'=>'id']);
        $recall = ['batch' => '', 'lot' => '', 'product' => '', 'affected' => []];

        if (! $rows) {
            return $recall;
        }

        $recall['batch'] = $rows[0]['batch'];
        $recall['lot'] = $rows[0]['lot'];
        $recall['product'] = $rows[0]['product'];

        foreach ($rows as $row) {
            $recall['affected'][] = [
                'location' => $row['location'],
                'qty' => (int) $row['qty'],
                'note' => $row['note'],
            ];
        }

        return $recall;
    }
}
