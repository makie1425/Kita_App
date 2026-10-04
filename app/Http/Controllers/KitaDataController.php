<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

class KitaDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            $pdo = DB::connection()->getPdo();

            return response()->json([
                'BUSINESS_DATE' => now()->toDateString(),
                'CATEGORIES' => $this->rows($pdo, 'SELECT name, status, classification, archivedAt, archivedBy FROM categories ORDER BY name'),
                'PRODUCTS' => $this->products($pdo),
                'BRANDS' => DB::table('brands')->orderBy('name')->get(),
                'SUBCATEGORIES' => DB::table('subcategories')->orderBy('name')->get(),
                'SUPPLIERS' => $this->suppliers($pdo),
                'ITEM_REQUESTS' => $this->itemRequests($pdo),
                'PURCHASE_ORDERS' => $this->purchaseOrders($pdo),
                'RECEIVING_RECORDS' => $this->receivingRecords($pdo),
                'ADJUSTMENTS' => $this->adjustments($pdo),
                'PROMOTIONS' => $this->promotions($pdo),
                'USERS' => $this->users($pdo),
                'AUDIT_LOGS' => $this->auditLogs($pdo),
                'FIELD_VERSION_HISTORY' => $this->rows($pdo, 'SELECT field, record, oldValue, newValue, user, ts, reason FROM field_version_history ORDER BY ts DESC, id DESC'),
                'NOTIFICATIONS' => DB::table('notifications')->where('recipientId', request()->user()->id)->where('recipientRole', request()->user()->role)->orderByDesc('id')->limit(50)->get(['id', 'type', 'priority', 'message'])->toArray(),
                'SALES_LOG' => $this->numericRows($pdo, 'SELECT date, hour, productId, qty, amount FROM sales_log ORDER BY date, hour, id', ['hour', 'productId', 'qty', 'amount']),
                'TRANSACTIONS' => $this->transactions($pdo),
                'BATCH_RECALL' => $this->batchRecall($pdo),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Database connection/query failed.',
                'message' => 'The requested data could not be loaded. Please try again.',
            ], 500);
        }
    }

    private function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);

        return $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    private function numericRows(PDO $pdo, string $sql, array $fields): array
    {
        return array_map(fn (array $row): array => $this->numericRow($row, $fields), $this->rows($pdo, $sql));
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
        return $this->numericRows(
            $pdo,
            'SELECT brandId, subcategoryId, size, sizeUnit, id, name, category, vatClass, price, cost, unitPrice, registrationQuantity, stock, minStock, unit, status, batch, lot, expiry, barcode, supplierId, parentId, variantLabel, purchaseUnit, stockUnit, conversionFactor, barcodeStatus, archivedAt, archivedBy FROM products ORDER BY id',
            ['id', 'price', 'cost', 'unitPrice', 'registrationQuantity', 'stock', 'minStock', 'supplierId', 'parentId', 'conversionFactor']
        );
    }

    private function suppliers(PDO $pdo): array
    {
        $suppliers = array_map(function (array $row): array {
            $row = $this->numericRow($row, ['id']);
            $row['callbackLog'] = $this->jsonValue($row['callbackLog'], []);
            $row['products'] = [];

            return $row;
        }, $this->rows($pdo, 'SELECT id, name, status, contact, phone, email, address, category, archivedAt, archivedBy, callbackLog FROM suppliers ORDER BY id'));

        $index = [];
        foreach ($suppliers as $key => $supplier) {
            $index[(int) $supplier['id']] = $key;
        }

        foreach ($this->rows($pdo, 'SELECT supplierId, productId, costPrice, preferred FROM supplier_products ORDER BY supplierId, productId') as $product) {
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
        $requests = $this->rows($pdo, 'SELECT id, category, status, dateRequested, requestedBy, adminNote, disapprovalReason, poId FROM item_requests ORDER BY dateRequested, id');
        $index = [];

        foreach ($requests as $key => $request) {
            $requests[$key]['lines'] = [];
            $index[$request['id']] = $key;
        }

        foreach ($this->rows($pdo, 'SELECT requestId, productId, name, qty, supplierId, confirmedQty, editedQty FROM item_request_lines ORDER BY id') as $line) {
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
        }, $this->rows($pdo, 'SELECT id, itemRequestId, supplierId, status, supplierCallback, orderedValue, deliveredValue, invoicedValue, paidValue, outstandingValue, cancelledValue, receivingRecordIds, created, confirmedQty FROM purchase_orders ORDER BY created, id'));

        $index = [];
        foreach ($orders as $key => $order) {
            $index[$order['id']] = $key;
        }

        foreach ($this->rows($pdo, 'SELECT poId, productId, name, orderedQty, unitCost, lineTotal, deliveredQty, outstandingAction FROM purchase_order_lines ORDER BY id') as $line) {
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
        }, $this->rows($pdo, 'SELECT id, poId, date, deliveryStatus, discrepancy, discrepancyType, supplierContact, outcome, adminApproval, barcodeAssignment FROM receiving_records ORDER BY date, id'));

        $index = [];
        foreach ($records as $key => $record) {
            $index[$record['id']] = $key;
        }

        foreach ($this->rows($pdo, 'SELECT receivingId, productId, poQty, deliveredQty, invoiceQty, conditionText FROM receiving_record_lines ORDER BY id') as $line) {
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
        }, $this->rows($pdo, 'SELECT id, productId, qtyChange, reason, status, photo, comment, remarks, date FROM adjustments ORDER BY date, id'));
    }

    private function promotions(PDO $pdo): array
    {
        $promotions = $this->numericRows($pdo, 'SELECT id, name, type, occasionName, discountPct, startDate, endDate, category FROM promotions ORDER BY id', ['id', 'discountPct']);
        $index = [];

        foreach ($promotions as $key => $promotion) {
            $promotions[$key]['productIds'] = [];
            $index[(int) $promotion['id']] = $key;
        }

        foreach ($this->rows($pdo, 'SELECT promotionId, productId FROM promotion_products ORDER BY promotionId, productId') as $product) {
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

        foreach ($this->rows($pdo, 'SELECT role, id, name, username, email, status, refundLimit, schedule, createdBy FROM users ORDER BY role, id') as $user) {
            $role = (new User(['role' => $user['role']]))->normalizedRole();
            if ($role === 'super_admin') {
                $role = 'superadmin';
            }
            unset($user['role']);
            $users[$role] ??= [];
            $users[$role][] = $this->numericRow($user, ['id', 'refundLimit']);
        }

        return $users;
    }

    private function auditLogs(PDO $pdo): array
    {
        return array_map(fn (array $row): array => [
            'ts' => $row['ts'],
            'user' => $row['user'],
            'action' => $row['action'],
            'record' => $row['record'],
            'before' => $row['beforeValue'],
            'after' => $row['afterValue'],
        ], $this->rows($pdo, 'SELECT ts, user, action, record, beforeValue, afterValue FROM audit_logs ORDER BY ts DESC, id DESC'));
    }

    private function transactions(PDO $pdo): array
    {
        $lines = DB::table('transaction_lines')->orderBy('id')->get()->groupBy('transaction_uuid');

        // UUIDs are random. The first sale-line ID records checkout insertion order,
        // including payments created within the same second, without renumbering sales.
        return DB::table('transactions')->orderByDesc('date')
            ->orderByDesc(DB::table('transaction_lines')->selectRaw('MIN(id)')
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
        $rows = $this->rows($pdo, 'SELECT batch, lot, product, location, qty, note FROM batch_recall_affected ORDER BY id');
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
