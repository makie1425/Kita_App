<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PurchaseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $admin;
    private int $product;
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager', 'name' => 'Receiving Manager']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Approving Admin']);
        $this->actingAs($this->manager);
        $this->supplier = $this->postJson('/api/suppliers', ['name' => 'Purchase Supplier', 'email' => 'supplier@example.com'])->assertCreated()->json('supplier.id');
        $this->postJson('/api/categories', ['name' => 'Supplies', 'classification' => 'Non-Perishable'])->assertCreated();
        $this->product = $this->postJson('/api/products', [
            'name' => 'New Item', 'category' => 'Supplies', 'barcode' => 'PURCHASE-ITEM', 'stock' => 0, 'unitPrice' => 2.50,
            'price' => 5, 'purchaseUnit' => 'Box', 'stockUnit' => 'Piece', 'conversionFactor' => 10, 'supplierId' => $this->supplier,
        ])->assertCreated()->json('product.id');
    }

    public function test_receiving_saves_edited_cost_and_retail_prices_without_changing_po_cost(): void
    {
        $id = $this->requestOrder();
        $this->approve($id);
        $body = $this->delivery();
        $body['lines'][0]['unitCost'] = 3.75;
        $body['lines'][0]['sellingPrice'] = 8.50;
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'unitPrice' => 3.75, 'price' => 8.50, 'stock' => 4]);
        $this->assertDatabaseHas('inventory_batches', ['productId' => $this->product, 'unitCost' => 3.75]);
        $this->assertDatabaseHas('receiving_record_lines', ['productId' => $this->product, 'unitCost' => 3.75]);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'unitCost' => 2.50]);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 4]);
    }

    public function test_over_balance_receipt_requires_separate_admin_approval_and_preserves_batch_costs(): void
    {
        $id=$this->requestOrder(10); $this->approve($id);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive',$this->delivery(6))->assertCreated();
        $body=$this->delivery(4,1); $body['lines'][0]['unitCost']=3.75; $body['lines'][0]['sellingPrice']=9;
        $this->postJson('/api/purchase-orders/'.$id.'/receive',$body)->assertUnprocessable();
        $this->assertDatabaseCount('receiving_records',1);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>6,'price'=>5]);
        $request=['requestKey'=>(string)Str::uuid(),'version'=>1,'amount'=>5,'reason'=>'Supplier increased unit cost'];
        $this->postJson('/api/purchase-orders/'.$id.'/budget-requests',$request)->assertCreated();
        $this->postJson('/api/purchase-orders/'.$id.'/budget-requests',$request)->assertOk();
        $approval=DB::table('purchase_budget_requests')->value('id');
        $this->patchJson('/api/purchase-budget-requests/'.$approval,['action'=>'Approved'])->assertForbidden();
        $this->postJson('/api/purchase-orders/'.$id.'/receive',$body)->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson('/api/purchase-budget-requests/'.$approval,['action'=>'Approved'])->assertOk();
        $this->patchJson('/api/purchase-budget-requests/'.$approval,['action'=>'Approved'])->assertOk();
        $this->assertDatabaseHas('purchase_orders',['id'=>$id,'orderedValue'=>25,'outstandingValue'=>15,'receivingVersion'=>2]);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive',$body)->assertConflict();
        $body['version']=2;
        $this->postJson('/api/purchase-orders/'.$id.'/receive',$body)->assertCreated()->assertJsonPath('status','Fully Received');
        $this->postJson('/api/purchase-orders/'.$id.'/receive',$body)->assertOk();
        $this->assertDatabaseHas('purchase_orders',['id'=>$id,'deliveredValue'=>30,'outstandingValue'=>0]);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>10,'price'=>9]);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('Additional amount approval history')->assertSee('Supplier increased unit cost')->assertSee('30.00');
        $this->assertDatabaseHas('inventory_batches',['productId'=>$this->product,'quantityReceived'=>6,'unitCost'=>2.50]);
        $this->assertDatabaseHas('inventory_batches',['productId'=>$this->product,'quantityReceived'=>4,'unitCost'=>3.75]);
    }

    public function test_stale_budget_request_cannot_be_approved_but_can_be_declined(): void
    {
        $id=$this->requestOrder(10); $this->approve($id);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/budget-requests',[
            'requestKey'=>(string)Str::uuid(),'version'=>0,'amount'=>5,'reason'=>'Price increase'
        ])->assertCreated();
        $approval=DB::table('purchase_budget_requests')->value('id');
        $this->postJson('/api/purchase-orders/'.$id.'/receive',$this->delivery(2))->assertCreated();
        $this->actingAs($this->admin)->patchJson('/api/purchase-budget-requests/'.$approval,['action'=>'Approved'])->assertConflict();
        $this->patchJson('/api/purchase-budget-requests/'.$approval,['action'=>'Declined'])->assertOk();
        $this->assertDatabaseHas('purchase_orders',['id'=>$id,'outstandingValue'=>20]);
    }

    public function test_substitutions_enforce_cost_and_outstanding_quantity_and_preserve_receipt_history(): void
    {
        $replacement = $this->postJson('/api/products', [
            'name' => 'Replacement', 'category' => 'Supplies', 'barcode' => 'REPLACEMENT', 'stock' => 0, 'conversionFactor' => 1,
            'unitPrice' => 2, 'price' => 4, 'stockUnit' => 'Piece', 'purchaseUnit' => 'Piece', 'supplierId' => $this->supplier,
        ])->assertCreated()->json('product.id');
        $id = $this->requestOrder(4);
        $this->approve($id);
        $body = $this->delivery(4);
        $body['lines'][0]['substituteProductId'] = $replacement;
        $body['lines'][0]['substitutionReason'] = 'damaged';
        $body['lines'][0]['unitCost'] = 3;
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $body['lines'][0]['unitCost'] = 2;
        DB::table('products')->where('id', $replacement)->update(['unitPrice' => 3]);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        DB::table('products')->where('id', $replacement)->update(['unitPrice' => 2]);
        $body['lines'][0]['qty'] = 5;
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $this->assertDatabaseCount('receiving_records', 0);
        $body['lines'][0]['qty'] = 4;
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated()->assertJsonPath('status', 'Fully Received');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->assertDatabaseHas('products', ['id' => $replacement, 'stock' => 4]);
        $this->assertDatabaseHas('receiving_record_lines', ['productId' => $replacement, 'name' => 'Replacement', 'conditionText' => 'Damaged replacement for product #'.$this->product.' (New Item)']);
        $this->assertDatabaseHas('inventory_batches', ['productId' => $replacement, 'unitCost' => 2]);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $replacement, 'stock' => 4]);
    }

    public function test_carryover_receives_under_later_po_without_duplicate_obligation(): void
    {
        $original=$this->requestOrder(20); $this->approve($original);
        $body=$this->delivery(15); $body['lines'][0]['damagedQty']=2; $body['lines'][0]['damageReason']='Broken';
        $receipt=$this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/receive',$body)->assertCreated()->json('id');
        $this->assertDatabaseHas('receiving_inspections',['receiptId'=>$receipt,'accepted'=>15,'damaged'=>2,'missing'=>3]);
        $snapshot=json_decode(DB::table('receiving_records')->where('id',$receipt)->value('inspection'),true);
        $this->assertSame(3,$snapshot[0]['missing']);
        $this->assertSame(2,$snapshot[0]['damaged']);
        $this->assertSame(5,$snapshot[0]['outstanding']);
        $this->getJson('/api/inventory-history?productId='.$this->product)->assertOk()->assertJsonPath('batches.0.poId',$original)->assertJsonPath('batches.0.receiptId',$receipt)->assertJsonPath('batches.0.receivedBy','Receiving Manager');
        $sourceLine=DB::table('purchase_order_lines')->where('poId',$original)->value('id');
        $next=$this->requestOrder(10); $this->approve($next);
        $link=['requestKey'=>(string)Str::uuid(),'sourceLine'=>$sourceLine,'targetPo'=>$next,'quantity'=>5];
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/carryovers',$link)->assertCreated();
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers',$link)->assertOk();
        $this->assertDatabaseCount('receiving_carryovers',1);
        $this->assertDatabaseHas('purchase_orders',['id'=>$next,'orderedValue'=>25]);
        $this->assertDatabaseHas('purchase_order_lines',['poId'=>$next,'orderedQty'=>15,'carryoverQty'=>5,'lineTotal'=>25]);
        $other=$link; $other['requestKey']=(string)Str::uuid();
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers',$other)->assertUnprocessable();
        $this->postJson('/api/purchase-orders/'.$original.'/receive',$this->delivery(1,2))->assertUnprocessable();
        $expensive=$this->delivery(3,1); $expensive['lines'][0]['unitCost']=10;
        $this->postJson('/api/purchase-orders/'.$next.'/receive',$expensive)->assertUnprocessable();
        $this->assertDatabaseHas('receiving_carryovers',['sourcePo'=>$original,'receivedQty'=>0]);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>15]);
        $partial=$this->delivery(3,1);
        $this->postJson('/api/purchase-orders/'.$next.'/receive',$partial)->assertCreated();
        $this->postJson('/api/purchase-orders/'.$next.'/receive',$partial)->assertOk();
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>18]);
        $this->assertDatabaseHas('purchase_order_lines',['poId'=>$original,'deliveredQty'=>15,'resolvedQty'=>3]);
        $this->assertDatabaseHas('purchase_orders',['id'=>$original,'deliveredValue'=>45,'outstandingValue'=>5]);
        $this->assertDatabaseHas('purchase_orders',['id'=>$next,'deliveredValue'=>0,'outstandingValue'=>25]);
        $this->carryAndReceive($next, $this->delivery(12,2))->assertCreated();
        $this->assertDatabaseHas('purchase_orders',['id'=>$original,'status'=>'Fully Received','deliveredValue'=>50,'outstandingValue'=>0]);
        $this->assertDatabaseHas('purchase_orders',['id'=>$next,'status'=>'Fully Received','orderedValue'=>25,'deliveredValue'=>25,'outstandingValue'=>0]);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>30]);
        $this->assertDatabaseHas('receiving_inspections',['receiptId'=>$receipt,'accepted'=>15,'missing'=>3,'damaged'=>2]);
        $this->assertDatabaseCount('receiving_carryover_resolutions',3);
        $batches=DB::table('inventory_batches')->whereNotNull('receiptId')->get();
        $this->assertCount(3,$batches);
        $this->assertCount(3,$batches->pluck('batchNumber')->unique());
        foreach($batches as $batch) { $this->assertStringStartsWith('BATCH-',$batch->batchNumber); $this->assertSame($batch->batchNumber,$batch->lotNumber); }
        $this->getJson('/api/purchasing')->assertOk()->assertJsonCount(2,'carryovers')->assertJsonCount(3,'resolutions');
    }

    public function test_shortage_automatically_creates_delivery_po_and_retry_does_not_duplicate_it(): void
    {
        $original = $this->requestOrder(20);
        $this->approve($original);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/receive', $this->delivery(15))->assertCreated();
        $link = ['requestKey'=>(string) Str::uuid(), 'sourceLine'=>DB::table('purchase_order_lines')->where('poId', $original)->value('id'), 'quantity'=>5];
        $next = $this->postJson('/api/purchase-orders/'.$original.'/carryovers', $link)->assertCreated()->json('poId');
        $this->assertNotSame($original, $next);
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers', $link)->assertOk()->assertJsonPath('poId', $next);
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseCount('receiving_carryovers', 1);
        $this->assertDatabaseHas('purchase_orders', ['id'=>$next, 'orderedValue'=>0, 'status'=>PurchaseWorkflow::WAITING]);
        $this->assertDatabaseHas('purchase_order_lines', ['poId'=>$next, 'orderedQty'=>5, 'carryoverQty'=>5, 'lineTotal'=>0]);
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers', array_replace($link, ['quantity'=>4]))->assertConflict();
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers', array_replace($link, ['requestKey'=>(string) Str::uuid()]))->assertUnprocessable();
        $this->assertDatabaseCount('purchase_orders', 2);
        $receipt = $this->postJson('/api/purchase-orders/'.$next.'/receive', $this->delivery(5, 1))->assertCreated()->json('id');
        $this->assertDatabaseHas('receiving_records', ['id'=>$receipt, 'poId'=>$next]);
        $this->assertDatabaseHas('inventory_batches', ['receiptId'=>$receipt, 'quantityReceived'=>5]);
        $this->assertDatabaseHas('products', ['id'=>$this->product, 'stock'=>20]);
        $this->assertDatabaseHas('purchase_orders', ['id'=>$original, 'status'=>'Fully Received', 'deliveredValue'=>50]);
        $this->assertDatabaseHas('purchase_orders', ['id'=>$next, 'status'=>'Fully Received', 'orderedValue'=>0, 'deliveredValue'=>0]);
    }

    public function test_more_shortages_can_share_the_automatically_created_delivery_po(): void
    {
        $original = $this->requestOrder(20);
        $this->approve($original);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/receive', $this->delivery(15))->assertCreated();
        $link = ['requestKey'=>(string) Str::uuid(), 'sourceLine'=>DB::table('purchase_order_lines')->where('poId', $original)->value('id'), 'quantity'=>2, 'targetPo'=>''];
        $this->actingAs($this->admin)->postJson('/api/purchase-orders/'.$original.'/carryovers', $link)->assertForbidden();
        $this->assertDatabaseCount('purchase_orders', 1);
        $next = $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/carryovers', $link)->assertCreated()->json('poId');
        $link = array_replace($link, ['requestKey'=>(string) Str::uuid(), 'targetPo'=>$next, 'quantity'=>3]);
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers', $link)->assertCreated()->assertJsonPath('poId', $next);
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseHas('purchase_order_lines', ['poId'=>$next, 'orderedQty'=>5, 'carryoverQty'=>5]);
        $receipt = $this->postJson('/api/purchase-orders/'.$next.'/receive', $this->delivery(5, 2))->assertCreated()->json('id');
        $this->assertDatabaseHas('receiving_records', ['id'=>$receipt, 'poId'=>$next]);
        $this->assertDatabaseHas('products', ['id'=>$this->product, 'stock'=>20]);
        $this->assertDatabaseHas('purchase_orders', ['id'=>$original, 'status'=>'Fully Received']);
    }

    public function test_damaged_only_receipt_and_releasing_carryover_preserve_inventory(): void
    {
        $original=$this->requestOrder(4); $this->approve($original);
        $body=$this->delivery(0); $body['lines'][0]['damagedQty']=2; $body['lines'][0]['damageReason']='Leaking';
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/receive',$body)->assertCreated();
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>0]);
        $this->assertDatabaseCount('inventory_batches',0);
        $this->assertDatabaseHas('receiving_inspections',['accepted'=>0,'damaged'=>2,'missing'=>2]);
        $next=$this->requestOrder(10);$this->approve($next);
        $link=['requestKey'=>(string)Str::uuid(),'sourceLine'=>DB::table('purchase_order_lines')->where('poId',$original)->value('id'),'targetPo'=>$next,'quantity'=>4];
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/carryovers',$link)->assertCreated();
        $id=DB::table('receiving_carryovers')->value('id');
        $this->postJson('/api/receiving-carryovers/'.$id.'/release')->assertOk();
        $this->postJson('/api/receiving-carryovers/'.$id.'/release')->assertOk();
        $this->assertDatabaseHas('purchase_order_lines',['poId'=>$next,'orderedQty'=>10,'carryoverQty'=>0]);
        $this->assertDatabaseHas('receiving_carryovers',['id'=>$id,'releasedQty'=>4]);
        $this->assertDatabaseHas('purchase_order_lines',['poId'=>$original,'resolvedQty'=>0]);
    }

    public function test_carryover_rejects_wrong_supplier_unapproved_orders_and_unauthorized_users(): void
    {
        $original=$this->requestOrder(4);$this->approve($original);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$original.'/receive',$this->delivery(1))->assertCreated();
        $next=$this->requestOrder(2);
        $line=DB::table('purchase_order_lines')->where('poId',$original)->value('id');
        $link=['requestKey'=>(string)Str::uuid(),'sourceLine'=>$line,'targetPo'=>$next,'quantity'=>3];
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers',$link)->assertUnprocessable();
        $this->approve($next);
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers',$link)->assertForbidden();
        $second=$this->actingAs($this->manager)->postJson('/api/suppliers',['name'=>'Other Supplier'])->assertCreated()->json('supplier.id');
        DB::table('purchase_orders')->where('id',$next)->update(['supplierId'=>$second]);
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers',$link)->assertUnprocessable();
        $this->assertDatabaseCount('receiving_carryovers',0);
        DB::table('purchase_orders')->where('id',$next)->update(['supplierId'=>$this->supplier]);
        $this->postJson('/api/purchase-orders/'.$original.'/carryovers',$link)->assertCreated();
        $this->postJson('/api/purchase-orders/'.$next.'/receive',$this->delivery(1,0))->assertConflict();
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>1]);
    }

    public function test_received_batches_trace_to_orders_movements_and_printed_expiration_report(): void
    {
        DB::table('products')->where('id',$this->product)->update(['manufacturer'=>'Original Factory']);
        $id=$this->requestOrder(4);$this->approve($id);
        $body=$this->delivery(4);$body['lines'][0]['expiryDate']=now()->addDays(5)->toDateString();
        $receipt=$this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive',$body)->assertCreated()->json('id');
        $batch=DB::table('inventory_batches')->where('receiptId',$receipt)->first();
        DB::table('products')->where('id',$this->product)->update(['manufacturer'=>'New Factory']);
        $this->getJson('/api/inventory-history')->assertOk()->assertJsonPath('batches.0.manufacturer','Original Factory')->assertJsonPath('batches.0.poId',$id)->assertJsonPath('movements.0.batchNumbers',$batch->batchNumber);
        $this->getJson('/reports/data?type=movements')->assertOk()->assertJsonPath('rows.0.batchNumbers',$batch->batchNumber);
        $this->getJson('/reports/data?type=receipts')->assertOk()->assertJsonPath('rows.0.manufacturer','Original Factory')->assertJsonPath('rows.0.poId',$id);
        $this->get('/reports/data?type=expiry&format=print')->assertOk()->assertSee('Original Factory')->assertSee($batch->batchNumber)->assertSee('Product ID')->assertSee('Expiration date');
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('Original Factory')->assertSee($batch->batchNumber)->assertSee('Product ID');
        $this->getJson('/reports/data?type=expiry')->assertOk()->assertJsonPath('rows.0.batch',$batch->batchNumber);
        DB::transaction(function () use($batch) {
            \App\Services\StockMovement::record($this->product,4,2,'checkout','BATCH-SALE',$this->manager);
            DB::table('products')->where('id',$this->product)->update(['stock'=>2]);
        });
        $this->getJson('/api/inventory-history')->assertOk()->assertJsonPath('movements.0.batchNumbers',$batch->batchNumber);
    }

    public function test_shared_delivery_keeps_original_pos_and_one_batch_with_followup_notes(): void
    {
        $old=$this->requestOrder(10); $this->approve($old);
        $first=$this->delivery(6); $first['lines'][0]['damagedQty']=1; $first['lines'][0]['damageReason']='Broken';
        $previous=$this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$old.'/receive',$first)->assertCreated()->json('id');
        $originalBatch=DB::table('receiving_records')->where('id',$previous)->value('deliveryBatch');
        $newProduct=$this->postJson('/api/products',['name'=>'New delivery product','category'=>'Supplies','barcode'=>'NEW-DELIVERY-PRODUCT','stock'=>0,'unitPrice'=>2.50,'price'=>5,'purchaseUnit'=>'Piece','stockUnit'=>'Piece','conversionFactor'=>1,'supplierId'=>$this->supplier])->assertCreated()->json('product.id');
        $new=$this->postJson('/api/purchase-requests',['category'=>'Supplies','lines'=>[['productId'=>$newProduct,'supplierId'=>$this->supplier,'qty'=>4]]])->assertCreated()->json('id');
        $this->approve($new);
        $body=['requestKey'=>(string)Str::uuid(),'supplierId'=>$this->supplier,'receivedDate'=>now()->toDateString(),'orders'=>[
            ['poId'=>$old,'version'=>1,'lines'=>[['productId'=>$this->product,'qty'=>4]]],
            ['poId'=>$new,'version'=>0,'supplierDocument'=>'NEW-INVOICE','paymentDueDate'=>now()->addDays(7)->toDateString(),'lines'=>[['productId'=>$newProduct,'qty'=>4]]],
        ]];
        $batch=$this->actingAs($this->manager)->postJson('/api/receiving-deliveries',$body)->assertCreated()->assertJsonCount(2,'receipts')->json('batchNumber');
        $this->assertNotSame($originalBatch,$batch);
        $this->assertSame(2,DB::table('receiving_records')->where('deliveryBatch',$batch)->count());
        $this->assertDatabaseHas('receiving_records',['poId'=>$new,'supplierDocument'=>'NEW-INVOICE','paymentDueDate'=>now()->addDays(7)->toDateString()]);
        $this->assertSame(2,DB::table('inventory_batches')->where('batchNumber',$batch)->where('lotNumber',$batch)->count());
        $this->assertDatabaseHas('purchase_orders',['id'=>$old,'orderedValue'=>25,'deliveredValue'=>25,'status'=>'Fully Received']);
        $this->assertDatabaseHas('purchase_orders',['id'=>$new,'orderedValue'=>10,'deliveredValue'=>10,'status'=>'Fully Received']);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>10]);
        $this->assertDatabaseHas('products',['id'=>$newProduct,'stock'=>4]);
        $this->assertDatabaseHas('receiving_inspections',['receiptId'=>$previous,'missing'=>3,'damaged'=>1]);
        $this->assertDatabaseCount('receiving_carryovers',0);
        $this->get('/purchase-orders/'.$old.'/report')->assertOk()->assertSee($batch)->assertSee($new)->assertSee('missing items and damaged items')->assertSee($previous);
        $this->postJson('/api/receiving-deliveries',$body)->assertOk()->assertJsonPath('batchNumber',$batch);
        $this->assertDatabaseCount('receiving_records',3);
        $body['orders'][0]['lines'][0]['qty']=3;
        $this->postJson('/api/receiving-deliveries',$body)->assertConflict();
    }

    public function test_shared_delivery_rolls_back_every_po_if_one_exceeds_its_balance(): void
    {
        $one=$this->requestOrder(2);$this->approve($one);
        $two=$this->requestOrder(2);$this->approve($two);
        $body=['requestKey'=>(string)Str::uuid(),'supplierId'=>$this->supplier,'receivedDate'=>now()->toDateString(),'orders'=>[
            ['poId'=>$one,'version'=>0,'lines'=>[['productId'=>$this->product,'qty'=>2]]],
            ['poId'=>$two,'version'=>0,'lines'=>[['productId'=>$this->product,'qty'=>2,'unitCost'=>3]]],
        ]];
        $this->actingAs($this->manager)->postJson('/api/receiving-deliveries',$body)->assertUnprocessable();
        $this->assertDatabaseCount('receiving_records',0);
        $this->assertDatabaseCount('receiving_deliveries',0);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>0]);
        $this->assertDatabaseHas('purchase_order_lines',['poId'=>$one,'deliveredQty'=>0]);
        $body['orders'][1]['lines'][0]['unitCost']=2.50;
        $body['orders'][0]['lines'][0]['sellingPrice']=5;
        $body['orders'][1]['lines'][0]['sellingPrice']=6;
        $this->postJson('/api/receiving-deliveries',$body)->assertUnprocessable();
        $body['orders'][1]['lines'][0]['sellingPrice']=5;
        $this->postJson('/api/receiving-deliveries',$body)->assertCreated();
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>4]);
    }

    public function test_shared_delivery_rejects_mixed_suppliers_and_unauthorized_posting(): void
    {
        $one=$this->requestOrder(2);$this->approve($one);
        $two=$this->requestOrder(2);$this->approve($two);
        $supplier=$this->actingAs($this->manager)->postJson('/api/suppliers',['name'=>'Another supplier'])->assertCreated()->json('supplier.id');
        DB::table('purchase_orders')->where('id',$two)->update(['supplierId'=>$supplier]);
        $body=['requestKey'=>(string)Str::uuid(),'supplierId'=>$this->supplier,'receivedDate'=>now()->toDateString(),'orders'=>[
            ['poId'=>$one,'version'=>0,'lines'=>[['productId'=>$this->product,'qty'=>2]]],
            ['poId'=>$two,'version'=>0,'lines'=>[['productId'=>$this->product,'qty'=>2]]],
        ]];
        $this->postJson('/api/receiving-deliveries',$body)->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/receiving-deliveries',$body)->assertForbidden();
        $this->assertDatabaseCount('receiving_records',0);
    }

    private function requestOrder(int $qty = 10): string
    {
        return $this->actingAs($this->manager)->postJson('/api/purchase-requests', ['category' => 'Supplies', 'notes' => 'For replenishment',
            'lines' => [['productId' => $this->product, 'qty' => $qty, 'supplierId' => $this->supplier]],
        ])->assertCreated()->assertJsonPath('status', 'Pending Approval')->json('id');
    }

    public function test_manufacturing_date_is_optional_validated_and_preserved_on_receipt(): void
    {
        $id = $this->requestOrder();
        $this->approve($id);
        $body = $this->delivery();
        $body['lines'][0]['manufacturedDate'] = now()->addDay()->toDateString();
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $this->assertDatabaseCount('receiving_records', 0);
        $body['lines'][0]['manufacturedDate'] = now()->subDays(3)->toDateString();
        $body['lines'][0]['expiryDate'] = now()->addDays(7)->toDateString();
        $body['lines'][0]['lotNumber'] = 'LOT-RECEIVED';
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated();
        foreach (['inventory_batches', 'receiving_record_lines'] as $table) {
            $this->assertDatabaseHas($table, ['productId' => $this->product, 'manufacturedDate' => $body['lines'][0]['manufacturedDate'], 'expiryDate' => $body['lines'][0]['expiryDate']]);
        }
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertOk();
        $this->assertDatabaseCount('inventory_batches', 1);
    }

    private function approve(string $id): void
    {
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 0])
            ->assertOk()->assertJsonPath('poId', $id)->assertJsonPath('status', PurchaseWorkflow::WAITING);
    }

    public function test_request_requires_explicit_supplier_even_when_product_has_default(): void
    {
        $body = ['category' => 'Supplies', 'lines' => [['productId' => $this->product, 'qty' => 1]]];
        foreach ([null, '', 0] as $supplier) {
            $body['lines'][0]['supplierId'] = $supplier;
            $this->postJson('/api/purchase-requests', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.supplierId');
        }
        unset($body['lines'][0]['supplierId']);
        $this->postJson('/api/purchase-requests', $body)->assertUnprocessable();
        $this->assertDatabaseCount('item_requests', 0);
        $body['lines'][0]['supplierId'] = $this->supplier;
        $this->postJson('/api/purchase-requests', $body)->assertCreated();
    }

    public function test_box_requests_convert_to_pieces_and_validate_pack_size(): void
    {
        $body = ['category' => 'Supplies', 'lines' => [['productId' => $this->product, 'supplierId' => $this->supplier, 'qty' => 2, 'orderUnit' => 'Box']]];
        $this->postJson('/api/purchase-requests', $body)->assertUnprocessable();
        $body['lines'][0]['piecesPerBox'] = 12;
        $id = $this->postJson('/api/purchase-requests', $body)->assertCreated()->json('id');
        $this->assertDatabaseHas('item_request_lines', ['requestId' => $id, 'qty' => 24, 'unit' => 'Piece']);
        $this->approve($id);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'orderedQty' => 24, 'unit' => 'Piece']);
        $this->actingAs($this->manager);
        $body['lines'][0]['piecesPerBox'] = 1000000;
        $this->postJson('/api/purchase-requests', $body)->assertUnprocessable();
        $body['lines'][0]['orderUnit'] = 'Piece';
        unset($body['lines'][0]['piecesPerBox']);
        $id = $this->postJson('/api/purchase-requests', $body)->assertCreated()->json('id');
        $this->assertDatabaseHas('item_request_lines', ['requestId' => $id, 'qty' => 2, 'unit' => 'Piece']);
    }

    public function test_case_orders_convert_bottles_and_preserve_purchase_unit(): void
    {
        DB::table('products')->where('id', $this->product)->update(['stockUnit' => 'Bottle', 'unit' => 'Bottle']);
        $body = ['category' => 'Supplies', 'lines' => [['productId' => $this->product, 'supplierId' => $this->supplier, 'qty' => 3, 'orderUnit' => 'Case']]];
        $this->postJson('/api/purchase-requests', $body)->assertUnprocessable();
        $body['lines'][0]['stockUnitsPerOrder'] = 24;
        $id = $this->postJson('/api/purchase-requests', $body)->assertCreated()->json('id');
        $this->assertDatabaseHas('item_request_lines', ['requestId' => $id, 'qty' => 72, 'unit' => 'Bottle', 'orderUnit' => 'Case', 'piecesPerBox' => 24]);
        $this->approve($id);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'orderedQty' => 72, 'unit' => 'Bottle']);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
    }

    public function test_package_options_include_configured_unit_and_reject_invalid_factors(): void
    {
        DB::table('products')->where('id', $this->product)->update(['purchaseUnit' => 'Tray']);
        $body = ['category' => 'Supplies', 'lines' => [['productId' => $this->product, 'supplierId' => $this->supplier, 'qty' => 2, 'orderUnit' => 'Tray', 'stockUnitsPerOrder' => 6]]];
        $this->postJson('/api/purchase-requests', $body)->assertCreated();
        foreach ([0, -1, 1.5, 1000000] as $factor) {
            $body['lines'][0]['stockUnitsPerOrder'] = $factor;
            $this->postJson('/api/purchase-requests', $body)->assertUnprocessable();
        }
        $body['lines'][0]['stockUnitsPerOrder'] = 6;
        $body['lines'][0]['orderUnit'] = 'Unknown unit';
        $this->postJson('/api/purchase-requests', $body)->assertUnprocessable();
        $body['lines'][0]['orderUnit'] = 'Case';
        $body['lines'][0]['piecesPerBox'] = 12;
        $this->postJson('/api/purchase-requests', $body)->assertUnprocessable();
    }

    public function test_po_sequence_continues_into_the_next_month_without_renumbering(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
        $first = $this->requestOrder();
        $this->assertSame('PO-2026-10-000001', $first);
        $this->travelTo(now()->setDate(2026, 11, 1));
        $this->assertSame('PO-2026-11-000002', $this->requestOrder());
        $this->assertDatabaseHas('item_requests', ['id' => $first]);
        $this->travelBack();
    }

    private function delivery(int $qty = 4, int $version = 0): array
    {
        return ['idempotencyKey' => (string) Str::uuid(), 'version' => $version,
            'receivedDate' => now()->toDateString(), 'lines' => [['productId' => $this->product, 'qty' => $qty]]];
    }

    private function carryAndReceive(string $source, array $body)
    {
        $next = null;
        foreach ($body['lines'] as $line) {
            $next = $this->postJson('/api/purchase-orders/'.$source.'/carryovers', [
                'requestKey'=>(string) Str::uuid(), 'sourceLine'=>DB::table('purchase_order_lines')->where('poId', $source)->where('productId', $line['productId'])->value('id'),
                'targetPo'=>$next, 'quantity'=>$line['qty'] + ($line['damagedQty'] ?? 0),
            ])->assertCreated()->json('poId');
        }
        $body['version'] = (int) DB::table('purchase_orders')->where('id', $next)->value('receivingVersion');
        return $this->postJson('/api/purchase-orders/'.$next.'/receive', $body);
    }

    public function test_fifty_ordered_forty_then_ten_received_uses_same_po_and_distinct_receipts(): void
    {
        $id = $this->requestOrder(50); $this->approve($id);
        $body = $this->delivery(40);
        $receipt = $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated()->assertJsonPath('status', 'Partially Received')->json('id');
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertOk();
        $this->assertDatabaseHas('purchase_orders', ['id'=>$id, 'status'=>'Partially Received']);
        $this->assertDatabaseHas('products', ['id'=>$this->product, 'stock'=>40]);
        $this->getJson('/api/purchasing')->assertOk()->assertJsonPath('orders.0.receipts.0.remarks', 'New Item: 10 missing');
        $final = $this->delivery(10, 1);
        $second = $this->postJson('/api/purchase-orders/'.$id.'/receive', $final)->assertCreated()->assertJsonPath('status', 'Fully Received')->assertJsonPath('poId', $id)->json('id');
        $this->assertNotSame($receipt, $second);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $final)->assertOk();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(1, 2))->assertConflict();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('receiving_records', 2);
        $this->assertDatabaseCount('receiving_carryovers', 0);
        $this->assertDatabaseHas('products', ['id'=>$this->product, 'stock'=>50]);
        $this->getJson('/api/purchasing')->assertOk()->assertJsonPath('orders.0.receipts.1.remarks', 'New Item: 10 missing');
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('10 missing')->assertSee($receipt)->assertSee($second);
    }

    public function test_damaged_units_are_returned_not_stocked_and_remarks_survive_optional_replacement(): void
    {
        $id = $this->requestOrder(50);
        $this->approve($id);
        $body = $this->delivery(40);
        $body['lines'][0] += ['damagedQty'=>3, 'damageReason'=>'Broken bottles'];
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated()->assertJsonPath('status', 'Partially Received');
        $remarks = 'New Item: 7 missing, 3 damaged — returned, not received';
        $this->getJson('/api/purchasing')->assertOk()->assertJsonPath('orders.0.remarks', $remarks);
        $this->assertDatabaseHas('products', ['id'=>$this->product, 'stock'=>40]);
        $this->assertDatabaseHas('inventory_batches', ['productId'=>$this->product, 'quantityReceived'=>40]);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(3, 1))->assertCreated();
        $this->assertDatabaseHas('products', ['id'=>$this->product, 'stock'=>43]);
        $this->getJson('/api/purchasing')->assertOk()->assertJsonPath('orders.0.receipts.1.remarks', $remarks);
        $this->getJson('/reports/data?type=receiving')->assertOk()->assertSee('returned, not received');
    }

    public function test_complete_workflow_preserves_id_and_posts_only_actual_stock_with_printable_history(): void
    {
        $id = $this->requestOrder();
        $this->assertMatchesRegularExpression('/^PO-'.now()->format('Y-m').'-[0-9]{6,}$/', $id);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->actingAs($this->admin)->getJson('/api/purchasing')->assertOk()->assertJsonPath('requests.0.id', $id);
        $this->approve($id);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->assertDatabaseHas('notifications', ['recipientId' => $this->manager->id, 'recipientRole' => 'manager', 'recordId' => $id, 'type' => 'Purchase request Approved']);
        $this->assertDatabaseHas('item_requests', ['id' => $id, 'poId' => $id, 'approvedById' => $this->admin->id, 'approvedByRole' => 'admin']);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())
            ->assertCreated()->assertJsonPath('poId', $id)->assertJsonPath('status', 'Partially Received');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 4]);
        $later = $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(6, 1))->assertCreated()->assertJsonPath('status', 'Fully Received')->json('poId');
        $this->assertSame($id, $later);
        $this->assertDatabaseHas('receiving_records', ['poId' => $id, 'deliveryReference' => $id.'-R001']);
        $this->assertDatabaseHas('receiving_records', ['poId' => $later, 'deliveryReference' => $later.'-R002']);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 10]);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'orderedQty' => 10, 'deliveredQty' => 10, 'resolvedQty'=>0, 'unit' => 'Piece']);
        $this->assertSame(2, DB::table('stock_movements')->where('referenceType', 'purchase_receiving')->count());
        $this->assertSame(2, DB::table('notifications')->where('recipientId', $this->admin->id)->where('recipientRole', 'admin')->whereIn('recordId', [$id, $later])->where('type', 'Stock received')->count());
        $this->actingAs($this->admin)->getJson('/api/purchasing')->assertOk()->assertJsonPath('orders.0.id', $later)->assertJsonPath('orders.0.status', 'Fully Received')->assertJsonCount(2, 'orders.0.receipts');
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('PURCHASE ORDER / RECEIVING REPORT')->assertSee($id)
            ->assertSee('Purchase Supplier')->assertSee('Approving Admin')->assertSee('Receiving Manager')->assertSee('Print Report');
    }

    public function test_receiving_retry_and_stale_version_cannot_double_stock_and_reference_is_generated(): void
    {
        $id = $this->requestOrder();$this->approve($id);
        $body = $this->delivery();
        $body['deliveryReference'] = 'MANUAL-REFERENCE';
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertOk();
        $this->assertSame(2, DB::table('notifications')->where('recordId', $id)->where('type', 'Stock received')->count());
        $changed = $body;$changed['lines'][0]['qty'] = 5;
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $changed)->assertConflict();
        $stale = $this->delivery();$stale['deliveryReference'] = 'NEXT-DELIVERY';
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $stale)->assertConflict();
        $duplicate = $this->delivery(4, 0);$duplicate['deliveryReference'] = ' delivery-0 ';
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $duplicate)->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 4]);
        $this->assertDatabaseCount('receiving_records', 1);
        $this->assertDatabaseHas('receiving_records', ['poId' => $id, 'deliveryReference' => $id.'-R001']);
    }

    public function test_declined_pending_and_unapproved_orders_cannot_receive(): void
    {
        $id = $this->requestOrder();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertNotFound();
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'disapproved'])->assertUnprocessable();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'disapproved', 'note' => 'Not needed'])->assertOk()->assertJsonPath('status', 'Declined');
        $this->actingAs($this->manager)->getJson('/api/purchasing')->assertOk()->assertJsonPath('requests.0.disapprovalReason', 'Not needed');
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertNotFound();
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
    }

    public function test_review_edits_same_request_rejects_stale_review_and_cannot_change_approved_quantities(): void
    {
        $id = $this->requestOrder();
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'modify', 'note' => 'Reduce to current demand.', 'revision' => 0, 'lines' => [['productId' => $this->product, 'qty' => 3]]])->assertOk();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 0])->assertConflict();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 1])->assertOk()->assertJsonPath('poId', $id);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'orderedQty' => 3]);
        $this->assertDatabaseCount('item_requests', 1);$this->assertDatabaseCount('purchase_orders', 1);
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved'])->assertConflict();
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/items', ['lines' => [['productId' => $this->product, 'qty' => 2]]])->assertConflict();
    }

    public function test_overdelivery_posts_actual_quantity_and_requires_notes_without_claiming_completion(): void
    {
        $id = $this->requestOrder(2);$this->approve($id);
        $body = $this->delivery(3);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $body['notes'] = 'Supplier delivered one extra piece, accepted by manager.';
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $this->postJson('/api/purchase-orders/'.$id.'/budget-requests', ['requestKey'=>(string)Str::uuid(),'version'=>0,'amount'=>2.50,'reason'=>'Accept one extra piece'])->assertCreated();
        $approval=DB::table('purchase_budget_requests')->value('id');
        $this->actingAs($this->admin)->patchJson('/api/purchase-budget-requests/'.$approval,['action'=>'Approved'])->assertOk();
        $this->actingAs($this->manager);
        $body['version']=1;
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated()->assertJsonPath('status', 'Fully Received');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 3]);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('Purchase items')->assertDontSee('Quantity discrepancies are recorded as remarks.');
    }

    public function test_invalid_delivery_rolls_back_stock_and_receipt_and_rejects_invalid_quantities(): void
    {
        $id = $this->requestOrder();$this->approve($id);
        $body = $this->delivery();$body['lines'][] = ['productId' => 999999, 'qty' => 1];
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        foreach ([0, -1, 1.5] as $qty) {
            $body = $this->delivery();$body['lines'][0]['qty'] = $qty;
            $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        }
        $body = $this->delivery();$body['receivedDate'] = now()->addDay()->toDateString();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $this->assertDatabaseCount('receiving_records', 0);$this->assertDatabaseCount('receiving_record_lines', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->assertSame(0, DB::table('stock_movements')->where('referenceType', 'purchase_receiving')->count());
    }

    public function test_purchase_permissions_and_supplier_validation(): void
    {
        $id = $this->requestOrder();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved'])->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => ' purchase supplier '])->assertUnprocessable();
        $this->approve($id);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertForbidden();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->getJson('/api/purchasing')->assertForbidden();
        $this->get('/purchase-orders/'.$id.'/report')->assertForbidden();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => 'Unauthorized'])->assertForbidden();
    }

    public function test_legacy_approved_draft_can_receive_without_rewriting_imported_ids(): void
    {
        $id = $this->requestOrder();$this->approve($id);
        DB::table('item_requests')->where('id', $id)->update(['status' => 'Approved', 'poId' => 'LEGACY-PO']);
        DB::table('purchase_orders')->where('id', $id)->update(['id' => 'LEGACY-PO', 'status' => 'Draft']);
        DB::table('purchase_order_lines')->where('poId', $id)->update(['poId' => 'LEGACY-PO']);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/LEGACY-PO/receive', $this->delivery())->assertCreated()->assertJsonPath('poId', 'LEGACY-PO');
        $this->assertDatabaseHas('purchase_orders', ['id' => 'LEGACY-PO', 'itemRequestId' => $id]);
        $this->get('/purchase-orders/LEGACY-PO/report')->assertOk()->assertSee('Legacy request reference: '.$id);
    }

    public function test_admin_removal_is_saved_and_historical_report_uses_snapshots(): void
    {
        DB::table('products')->insert(['id' => 100, 'name' => 'Second Item', 'category' => 'Supplies', 'stock' => 0, 'status' => 'Active', 'unit' => 'Piece', 'stockUnit' => 'Piece', 'unitPrice' => 1, 'supplierId' => $this->supplier]);
        $id = $this->postJson('/api/purchase-requests', ['category' => 'Supplies', 'lines' => [['supplierId' => $this->supplier, 'productId' => $this->product, 'qty' => 2], ['supplierId' => $this->supplier, 'productId' => 100, 'qty' => 1]]])->assertCreated()->json('id');
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'modify', 'note' => 'Second item is not needed.', 'revision' => 0, 'lines' => [['productId' => $this->product, 'qty' => 2]]])->assertOk();
        $this->assertDatabaseMissing('item_request_lines', ['requestId' => $id, 'productId' => 100]);
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 1])->assertOk();
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(2))->assertCreated();
        DB::table('products')->where('id', $this->product)->update(['name' => 'Renamed Item']);
        DB::table('suppliers')->where('id', $this->supplier)->update(['name' => 'Renamed Supplier']);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('New Item')->assertSee('Purchase Supplier')->assertDontSee('Renamed Item')->assertDontSee('Second Item');
    }

    public function test_quantity_changes_require_a_reason_even_when_approving_directly(): void
    {
        $id = $this->requestOrder();
        $this->actingAs($this->admin);
        foreach (['modify', 'approved'] as $action) {
            foreach ([null, '   '] as $note) {
                $this->patchJson('/api/purchase-requests/'.$id, ['action' => $action, 'note' => $note,
                    'revision' => 0, 'lines' => [['productId' => $this->product, 'qty' => 3]]])
                    ->assertUnprocessable()->assertJsonValidationErrors('note');
            }
        }
        $this->assertDatabaseHas('item_requests', ['id' => $id, 'revision' => 0]);
        $this->assertDatabaseHas('item_request_lines', ['requestId' => $id, 'qty' => 10, 'confirmedQty' => null]);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'modify', 'note' => 'Budget limit',
            'revision' => 0, 'lines' => [['productId' => $this->product, 'qty' => 3]]])->assertOk();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 1])->assertOk();
        $this->assertDatabaseHas('item_requests', ['id' => $id, 'adminNote' => 'Budget limit']);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'orderedQty' => 3]);
    }

    public function test_po_numbers_are_allocated_in_database_and_retries_return_original_request(): void
    {
        $body = ['submissionKey' => (string) Str::uuid(), 'category' => 'Supplies', 'lines' => [['supplierId' => $this->supplier, 'productId' => $this->product, 'qty' => 2]]];
        $first = $this->postJson('/api/purchase-requests', $body)->assertCreated()->json('id');
        $this->postJson('/api/purchase-requests', $body)->assertOk()->assertJsonPath('id', $first);
        $this->assertDatabaseCount('item_requests', 1);
        $changed = $body;$changed['lines'][0]['qty'] = 3;
        $this->postJson('/api/purchase-requests', $changed)->assertConflict();
        $second = $this->requestOrder();
        $this->assertSame('PO-'.now()->format('Y-m').'-000001', $first);
        $this->assertSame('PO-'.now()->format('Y-m').'-000002', $second);
        $this->assertDatabaseHas('inventory_sequences', ['name' => 'purchase_orders', 'lastValue' => 2]);
        $this->getJson('/api/purchasing')->assertOk()->assertJsonCount(2, 'requests');
        $this->postJson('/api/purchase-requests', $body + ['id' => 'PO-MANUAL'])->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_po_format_preserves_legacy_ids_and_continues_sequence_across_years(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31)->startOfDay());
        DB::table('item_requests')->insert(['id' => '42', 'status' => 'Pending Approval']);
        DB::table('inventory_sequences')->where('name', 'purchase_orders')->update(['lastValue' => 42]);
        $this->assertSame('PO-2026-12-000043', $this->requestOrder());
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('PO-2027-01-000044', $this->requestOrder());
        $this->assertDatabaseHas('item_requests', ['id' => '42']);
        $this->travelBack();
    }

    public function test_po_number_skips_an_existing_formatted_id(): void
    {
        $first = $this->requestOrder();
        DB::table('inventory_sequences')->where('name', 'purchase_orders')->update(['lastValue' => 0]);
        $second = $this->requestOrder();
        $this->assertNotSame($first, $second);
        $this->assertSame('PO-'.now()->format('Y-m').'-000002', $second);
    }

    public function test_five_missing_are_reported_while_received_items_post_and_history_is_preserved(): void
    {
        $id = $this->requestOrder(20);
        $this->approve($id);
        $receipt = $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(15))
            ->assertCreated()->assertJsonPath('inspection.0.missing', 5)->assertJsonPath('inspection.0.received', 15)->json('id');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 15]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'status' => 'Partially Received']);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('MISSING ITEM / SHORTAGE REPORT');
        $notification = DB::table('notifications')->where('recipientId', $this->admin->id)->where('recipientRole', 'admin')->where('type', 'Stock received')->value('message');
        $this->assertStringContainsString('5 missing', $notification);
        $later = $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(5, 1))->assertCreated()->assertJsonPath('inspection.0.missing', 0)->json('poId');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 20]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'status' => 'Fully Received']);
        $snapshot = json_decode(DB::table('receiving_records')->where('id', $receipt)->value('inspection'), true);
        $this->assertSame(5, $snapshot[0]['missing']);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('MISSING ITEM / SHORTAGE REPORT')->assertSee($later);
        $this->get('/purchase-orders/'.$later.'/report')->assertOk()->assertSee('RECEIVING INSPECTION REPORT');
    }
}
