<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasingReferenceFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $admin;
    private int $product;
    private int $supplier;
    private string $po;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager=User::factory()->create(['role'=>'manager','name'=>'Receiving Manager']);
        $this->admin=User::factory()->create(['role'=>'admin','name'=>'Admin']);
        $this->actingAs($this->manager);
        $this->supplier=$this->postJson('/api/suppliers',['name'=>'Water Supplier'])->assertCreated()->json('supplier.id');
        $this->postJson('/api/categories',['name'=>'Supplies','classification'=>'Non-Perishable'])->assertCreated();
        $this->product=$this->postJson('/api/products',['name'=>'Water','category'=>'Supplies','barcode'=>'WATER','stock'=>0,'unitPrice'=>20,'price'=>30,'purchaseUnit'=>'Piece','stockUnit'=>'Piece','conversionFactor'=>1,'supplierId'=>$this->supplier])->assertCreated()->json('product.id');
        $this->po=$this->postJson('/api/purchase-requests',['category'=>'Supplies','expectedDeliveryDate'=>now()->addDay()->toDateString(),'lines'=>[['productId'=>$this->product,'supplierId'=>$this->supplier,'qty'=>100]]])->assertCreated()->json('id');
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$this->po,['action'=>'approved'])->assertOk();
    }

    private function receive(int $quantity, int $version=0, int $damage=0): string
    {
        return $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$this->po.'/receive',[
            'idempotencyKey'=>(string)Str::uuid(),'version'=>$version,'receivedDate'=>now()->toDateString(),'paymentDueDate'=>now()->addDays(7)->toDateString(),'supplierDocument'=>'INV-'.$version,
            'lines'=>[['productId'=>$this->product,'qty'=>$quantity,'damagedQty'=>$damage,'damageReason'=>$damage?'Broken bottles':null]],
        ])->assertCreated()->json('id');
    }

    public function test_full_reference_flow_updates_receipts_payables_and_reports_without_duplicate_stock(): void
    {
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>0]);
        $this->getJson('/api/supplier-accounts')->assertOk()->assertJsonCount(0,'receipts');
        $plan=['expectedDeliveryDate'=>now()->addDays(2)->toDateString(),'supplierSentDate'=>now()->toDateString(),'supplierSentVia'=>'Printed copy','supplierSentNote'=>'Handed to supplier'];
        $this->patchJson('/api/purchase-orders/'.$this->po.'/plan',$plan)->assertOk();
        $this->get('/purchase-orders/'.$this->po.'/order')->assertOk()->assertSee('2,000.00')->assertSee('Water Supplier');
        $first=$this->receive(60,0,5);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>60]);
        $this->getJson('/api/supplier-accounts')->assertOk()->assertJsonPath('receipts.0.balance',1200)->assertJsonPath('receipts.0.accountRemark','Unpaid')->assertJsonMissingPath('receipts.0.payloadHash');
        $this->getJson('/reports/data?type=outstanding_orders')->assertOk()->assertJsonPath('rows.0.outstandingQty',40);
        $this->getJson('/reports/data?type=purchases&manager=Receiving%20Manager')->assertOk()->assertJsonPath('rows.0.amount',1200);
        $payment=['requestKey'=>(string)Str::uuid(),'kind'=>'Payment','date'=>now()->toDateString(),'amount'=>200,'reference'=>'BANK-001'];
        $this->postJson('/api/receipts/'.$first.'/settlements',$payment)->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/receipts/'.$first.'/settlements',$payment)->assertCreated();
        $this->postJson('/api/receipts/'.$first.'/settlements',$payment)->assertOk();
        $this->postJson('/api/receipts/'.$first.'/settlements',array_replace($payment,['amount'=>201]))->assertConflict();
        $this->postJson('/api/receipts/'.$first.'/settlements',array_replace($payment,['requestKey'=>(string)Str::uuid(),'amount'=>2000,'reference'=>'TOO-MUCH']))->assertUnprocessable();
        $credit=array_replace($payment,['requestKey'=>(string)Str::uuid(),'kind'=>'Credit note','amount'=>100,'reference'=>'CREDIT-001']);
        $this->postJson('/api/receipts/'.$first.'/settlements',$credit)->assertCreated();
        $second=$this->receive(40,1);
        $this->assertNotSame($first,$second);
        $this->assertDatabaseCount('purchase_orders',1);
        $this->assertDatabaseCount('receiving_records',2);
        $this->assertDatabaseCount('supplier_settlements',2);
        $this->assertDatabaseHas('purchase_orders',['id'=>$this->po,'status'=>'Fully Received']);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>100]);
        $this->getJson('/reports/data?type=payables')->assertOk()->assertJsonPath('summary.Unpaid balance (PHP)','1,700.00');
        $this->getJson('/reports/data?type=outstanding_orders')->assertOk()->assertJsonCount(0,'rows');
        $this->getJson('/reports/data?type=supplier_book')->assertOk()->assertJsonCount(4,'rows')->assertJsonPath('summary.Period purchases (PHP)','2,000.00');
        $this->get('/receipts/'.$first.'/report')->assertOk()->assertSee('35 missing')->assertDontSee($second);
        foreach (['purchase_orders','outstanding_orders','purchases','supplier_book','payables'] as $type) {
            $this->get('/reports/data?type='.$type.'&format=csv')->assertOk();
            $this->get('/reports/data?type='.$type.'&format=print')->assertOk();
        }
        $this->get('/reports/data?type=payables&format=pdf')->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->getJson('/reports/data?type=purchases&manager=Nobody')->assertOk()->assertJsonCount(0,'rows');
    }

    public function test_admin_can_void_entry_and_cancel_only_the_remaining_purchase(): void
    {
        $receipt=$this->receive(60);
        $body=['requestKey'=>(string)Str::uuid(),'kind'=>'Payment','date'=>now()->toDateString(),'amount'=>1200,'reference'=>'CASH-001'];
        $entry=$this->actingAs($this->admin)->postJson('/api/receipts/'.$receipt.'/settlements',$body)->assertCreated()->json('id');
        $this->getJson('/reports/data?type=payables')->assertOk()->assertJsonPath('rows.0.balance',0);
        $this->postJson('/api/supplier-settlements/'.$entry.'/void',['reason'=>'Wrong payment receipt'])->assertOk();
        $this->postJson('/api/supplier-settlements/'.$entry.'/void',['reason'=>'Wrong payment receipt'])->assertOk();
        $this->getJson('/reports/data?type=payables')->assertOk()->assertJsonPath('rows.0.balance',1200);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$this->po.'/cancel-remainder',['reason'=>'Supplier cannot deliver'])->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/purchase-orders/'.$this->po.'/cancel-remainder',['reason'=>'Supplier cannot deliver'])->assertOk();
        $this->getJson('/reports/data?type=outstanding_orders')->assertOk()->assertJsonCount(0,'rows');
        $this->getJson('/reports/data?type=payables')->assertOk()->assertJsonPath('rows.0.balance',1200);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>60]);
        $this->assertDatabaseHas('purchase_orders',['id'=>$this->po,'status'=>'Cancelled','cancelledValue'=>800]);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$this->po.'/receive',['idempotencyKey'=>(string)Str::uuid(),'version'=>2,'receivedDate'=>now()->toDateString(),'lines'=>[['productId'=>$this->product,'qty'=>40]]])->assertConflict();
    }

    public function test_old_completed_shortage_can_receive_remaining_on_original_po(): void
    {
        $first=$this->receive(60);
        DB::table('purchase_orders')->where('id',$this->po)->update(['status'=>'Completed']);
        DB::table('item_requests')->where('id',$this->po)->update(['status'=>'Completed']);
        $this->receive(40,1);
        $this->assertDatabaseCount('purchase_orders',1);
        $this->assertDatabaseHas('purchase_orders',['id'=>$this->po,'status'=>'Fully Received']);
        $this->assertSame(40,json_decode(DB::table('receiving_records')->where('id',$first)->value('inspection'),true)[0]['missing']);
    }

    public function test_request_prices_are_snapshotted_and_invalid_prices_rejected(): void
    {
        $this->actingAs($this->manager);
        $body=['category'=>'Supplies','lines'=>[['productId'=>$this->product,'supplierId'=>$this->supplier,'qty'=>5,'unitCost'=>19.50]]];
        $id=$this->postJson('/api/purchase-requests',$body)->assertCreated()->json('id');
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id,['action'=>'approved'])->assertOk();
        $this->assertDatabaseHas('purchase_order_lines',['poId'=>$id,'unitCost'=>19.50,'lineTotal'=>97.50]);
        $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>0,'unitPrice'=>20]);
        $body['lines'][0]['unitCost']=-1;
        $this->actingAs($this->manager)->postJson('/api/purchase-requests',$body)->assertUnprocessable();
    }

    public function test_legacy_costs_and_payments_are_flagged_and_not_silently_paid_twice(): void
    {
        $receipt=$this->receive(60);
        DB::table('purchase_orders')->where('id',$this->po)->update(['paidValue'=>100]);
        $this->getJson('/api/supplier-accounts')->assertOk()->assertJsonPath('receipts.0.accountRemark','Review legacy costs / payments')->assertJsonPath('receipts.0.balance',null);
        $this->actingAs($this->admin)->postJson('/api/receipts/'.$receipt.'/settlements',['requestKey'=>(string)Str::uuid(),'kind'=>'Payment','date'=>now()->toDateString(),'amount'=>50,'reference'=>'UNKNOWN'])->assertConflict();
        $this->assertDatabaseCount('supplier_settlements',0);
    }
}
