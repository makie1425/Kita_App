<?php
namespace App\Console\Commands;
use App\Models\User;
use App\Services\{WorkspaceRead,PurchaseWorkflow,StockReceiptWorkflow,SaleCheckout};
use App\Http\Controllers\PurchasingWorkflowController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\{Carbon,Str};
use Illuminate\Http\Request;

class ExpandGroceryPresentation extends Command
{
 protected $signature='presentation:expand-grocery {admin} {manager} {--apply}';
 protected $description='Resume the version 2 grocery scenarios in the verified original presentation workspace';
 private User $manager; private User $admin; private int $workspace; private $anchor;
 private function request(User $actor,array $data): Request {Auth::setUser($actor);$r=Request::create('/','POST',$data);$r->setUserResolver(fn()=>$actor);return $r;}
 private function step(string $key,callable $work) {
  request()->attributes->set('workspace_scope_snapshot',true);
  try { return DB::transaction(function()use($key,$work){
   DB::table('workspaces')->where('id',$this->workspace)->lockForUpdate()->first();
   $saved=DB::table('grocery_expansion_steps')->where('workspace_id',$this->workspace)->where('step',$key)->first();
   if($saved)return json_decode($saved->result,true);
   $result=$work();DB::table('grocery_expansion_steps')->insert(['workspace_id'=>$this->workspace,'step'=>$key,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>new \DateTimeImmutable()]);
   $this->line('Grocery expansion committed: '.$key);return $result;
  }); } finally {request()->attributes->remove('workspace_scope_snapshot');request()->attributes->remove('workspace_verified_scopes');}
 }
 private function insert(string $table,array $data): int {return DB::table($table)->insertGetId($data+['workspace_id'=>$this->workspace]);}
 public function handle(): int {
  if($this->call('presentation:check-accounts',['admin'=>$this->argument('admin'),'manager'=>$this->argument('manager')]))return self::FAILURE;
  $this->manager=User::where('email',$this->argument('manager'))->firstOrFail();$this->admin=User::where('email',$this->argument('admin'))->firstOrFail();
  $this->workspace=WorkspaceRead::forUser($this->manager);
  $original=DB::table('grocery_imports')->where('workspace_id',$this->workspace)->first();
  if(!$original||!$original->completed||(int)$original->manager_id!==(int)$this->manager->id){$this->error('Complete the original owned grocery import first.');return self::FAILURE;}
  if(!$this->option('apply')){$this->info('Adds 70 products, 5 suppliers, 30 subcategories, 49 PO/request scenarios and 350 sales. Existing records are preserved.');return self::SUCCESS;}
  $actor=Auth::user();$clock=Carbon::getTestNow();
  try {
   Auth::setUser($this->manager);
   $this->anchor=Carbon::parse($this->step('v2-anchor',fn()=>now('Asia/Manila')->toDateString()),'Asia/Manila')->startOfDay();
   $groups=[
    ['Frozen Foods','Cold Harvest Distribution','Frozen Vegetables|Frozen Meat|Frozen Seafood|Frozen Snacks|Ice Cream|Frozen Fruit','Mixed Vegetables|Green Peas|Sweet Corn|Chicken Nuggets|Pork Siomai|Chicken Siomai|Fish Fillet|Shrimp|Squid Rings|French Fries|Spring Rolls|Vanilla Ice Cream|Strawberry Ice Cream|Frozen Mango'],
    ['Bakery','Morning Oven Supply','Bread|Rolls|Cakes|Pastries|Flatbread|Breadcrumbs','White Bread|Wheat Bread|Raisin Bread|Pandesal|Dinner Rolls|Burger Buns|Hotdog Buns|Butter Cake|Banana Loaf|Chocolate Muffin|Cheese Ensaymada|Pita Bread|Tortilla Wraps|Bread Crumbs'],
    ['Fresh Produce','Green Basket Trading','Leafy Vegetables|Root Crops|Fruit Vegetables|Citrus|Tropical Fruit|Aromatics','Cabbage|Pechay|Lettuce|Carrot|Potato|Sweet Potato|Tomato|Cucumber|Eggplant|Orange|Calamansi|Banana|Papaya|Ginger'],
    ['Breakfast Foods','Sunrise Pantry Supply','Oats|Cereal|Spreads|Coffee|Tea|Breakfast Mixes','Rolled Oats|Quick Oats|Corn Flakes|Chocolate Cereal|Fruit Granola|Peanut Butter|Strawberry Jam|Coconut Spread|Instant Coffee|Ground Coffee|Black Tea|Green Tea|Pancake Mix|Waffle Mix'],
    ['Pet Supplies','Happy Paws Wholesale','Dog Food|Cat Food|Treats|Litter|Pet Care|Accessories','Puppy Kibble|Adult Dog Kibble|Senior Dog Kibble|Chicken Dog Food|Kitten Kibble|Adult Cat Kibble|Tuna Cat Food|Salmon Cat Food|Dog Biscuits|Cat Treats|Cat Litter|Pet Shampoo|Pet Wipes|Feeding Bowl']
   ];
   $catalog=[];
   foreach($groups as $g=>[$category,$supplierName,$subs,$names]){
    $catalog=array_merge($catalog,$this->step('v2-catalog-'.$g,function()use($category,$supplierName,$subs,$names,$g){
     DB::table('inventory_sequences')->whereIn('name',['products','suppliers'])->orderBy('name')->lockForUpdate()->get();
     $this->insert('categories',['name'=>$category,'status'=>'Active','classification'=>$g<3?'Perishable':'Non-Perishable']);
     $supplier=(int)DB::table('suppliers')->max('id')+1;
     DB::table('suppliers')->insert(['id'=>$supplier,'workspace_id'=>$this->workspace,'name'=>$supplierName,'category'=>$category,'status'=>'Active','contact'=>'Sales Department','email'=>'orders@'.['coldharvest','morningoven','greenbasket','sunrisepantry','happypaws'][$g].'.example.invalid','address'=>'Calapan City, Oriental Mindoro']);
     $subIds=[];foreach(explode('|',$subs) as $name)$subIds[]=$this->insert('subcategories',['name'=>$name,'category'=>$category,'status'=>'Active']);
     $rows=[];
     foreach(explode('|',$names) as $i=>$name){
      $id=(int)DB::table('products')->max('id')+1;$cost=25+$g*12+$i*5;
      $row=['id'=>$id,'name'=>$name,'category'=>$category,'subcategoryId'=>$subIds[min(5,intdiv($i*6,14))],'supplierId'=>$supplier,'status'=>'Active','unit'=>'Piece','stockUnit'=>'Piece','purchaseUnit'=>'Case','conversionFactor'=>12,'stock'=>0,'minStock'=>10,'unitPrice'=>$cost,'price'=>round($cost*1.25,2),'vatClass'=>'VATable','barcode'=>'G'.$this->workspace.'V2'.str_pad((string)($g*14+$i),5,'0',STR_PAD_LEFT)];
      DB::table('products')->insert($row+['workspace_id'=>$this->workspace]);$this->insert('supplier_products',['supplierId'=>$supplier,'productId'=>$id,'costPrice'=>$cost,'preferred'=>true]);$rows[]=$row;
     }return $rows;
    }));
   }
   for($i=0;$i<49;$i++)$this->step('v2-order-'.$i,function()use($i,$catalog){
    $date=$this->anchor->copy()->subDays($i<30?400:20)->addDays($i<30?$i:0);Carbon::setTestNow($date);
    $p=$catalog[$i<30?$i:($i+11)%70];$qty=$i<30?500:20;
    $workflow=new PurchaseWorkflow;
    $id=$workflow->create($this->request($this->manager,['submissionKey'=>(string)Str::uuid(),'category'=>$p['category'],'notes'=>'Scheduled grocery replenishment.','lines'=>[['productId'=>$p['id'],'qty'=>$qty,'unitCost'=>$p['unitPrice'],'supplierId'=>$p['supplierId']]]]))->getData(true)['id'];
    if($i>=30&&$i<34)return $id;
    if($i>=34&&$i<38){$workflow->review($this->request($this->admin,['action'=>'proceed','note'=>'Checking supplier stock and requested quantities.']),$id);return $id;}
    if($i>=38&&$i<42){$workflow->review($this->request($this->admin,['action'=>'disapproved','note'=>'Requested quantities exceed the replenishment budget.']),$id);return $id;}
    $workflow->review($this->request($this->admin,['action'=>'approved','note'=>'Replenishment approved.']),$id);
    if($i>=42&&$i<45){(new PurchasingWorkflowController)->cancelRemainder($this->request($this->manager,['reason'=>'Supplier cannot fulfill the requested quantities.']),$id);return $id;}
    if($i<30){
     $lots=[];for($j=0;$j<5;$j++)$lots[]=['quantity'=>100,'supplierLot'=>'G'.$this->workspace.'-'.$i.'-'.$j,'manufacturedDate'=>$date->copy()->subDays(2)->toDateString(),'expiryDate'=>$this->anchor->copy()->addDays($i<20?365:($i<24?1+$j:($i<26?-5-$j:90)))->toDateString()];
     $this->receive($id,$p,500,0,'current',$lots);
    }elseif($i>=45&&$i<48){
     $lots=[['quantity'=>10,'supplierLot'=>'R'.$this->workspace.'-'.$i,'manufacturedDate'=>$date->copy()->subDay()->toDateString(),'expiryDate'=>$this->anchor->copy()->addDays(180)->toDateString()]];
     $this->receive($id,$p,15,5,'current',$lots);
     if($i===47){$lots[0]['quantity']=5;$lots[0]['supplierLot'].='-REPLACEMENT';$this->receive($id,$p,5,0,'damaged',$lots);}
    }
    return $id;
   });
   for($i=0;$i<350;$i++)$this->step('v2-sale-'.$i,function()use($i,$catalog){
    Carbon::setTestNow($this->anchor->copy()->subDays($i%181)->setTime($i%181===0?0:9+($i%10),$i%181===0?0:($i*7)%60));
    $p=$catalog[$i%20];$data=['uuid'=>'G'.$this->workspace.'-V2-SALE-'.$i,'items'=>[['productId'=>$p['id'],'qty'=>1+$i%4]]];
    $r=$this->request($this->manager,[]);$sale=SaleCheckout::prepare($r,$data);SaleCheckout::save($r,$data,$sale,'Unused','Cash',(int)(ceil($sale['total']/10000)*10000),notify:false);return $data['uuid'];
   });
   for($i=0;$i<8;$i++)$this->step('v2-adjustment-'.$i,function()use($i,$catalog){
    Carbon::setTestNow($this->anchor->copy()->startOfDay());
    $p=$catalog[$i<4?26+$i:28+$i%2];$operations=new \App\Http\Controllers\OperationsController;
    $qty=$i<4?DB::table('products')->where('id',$p['id'])->value('stock')-($i<2?0:5):2;
    $id=$operations->storeAdjustment($this->request($this->manager,['productId'=>$p['id'],'qtyChange'=>-$qty,'reason'=>'Damaged stock','comment'=>'Packaging damaged during storage; counted for stock adjustment.']))->getData(true)['id'];
    if($i<6)$operations->updateAdjustment($this->request($this->admin,['status'=>$i<4?'Approved':'Rejected']),$id);
    return $id;
   });
   $this->info('Grocery expansion complete. Checkpoints committed; rerunning does not duplicate records.');
   return self::SUCCESS;
  }catch(\Throwable $e){$this->error('Grocery expansion stopped; completed checkpoints retained: '.$e->getMessage());return self::FAILURE;}
  finally{Carbon::setTestNow($clock);if($actor)Auth::setUser($actor);else Auth::forgetUser();}
 }
 private function receive(string $id,array $p,int $delivered,int $damaged,string $type,array $lots):void {
  $order=DB::table('purchase_orders')->where('id',$id)->first();$flow=new StockReceiptWorkflow;
  $result=$flow->submit($this->request($this->manager,['requestKey'=>(string)Str::uuid(),'primaryPo'=>$id,'supplierId'=>$p['supplierId'],'deliveryDate'=>now()->toDateString(),'supplierReference'=>'Delivery '.$id,'notes'=>$damaged?'Damaged packaging returned to supplier; replacement requested.':'Goods counted and inspected.','entries'=>[['poId'=>$id,'version'=>$order->receivingVersion,'productId'=>$p['id'],'type'=>$type,'delivered'=>$delivered,'damaged'=>$damaged,'damageReason'=>$damaged?'Damaged packaging':'','disposition'=>'return','unitCost'=>$p['unitPrice'],'lots'=>$lots]]]))->getData(true);
  if($result['status']==='Pending Approval'){
   $flow->review($this->request($this->admin,['action'=>'Approved','note'=>'Inspection reviewed; accept good stock and track replacement quantities.']),$result['id']);
   $flow->post($this->request($this->manager,[]),$result['id']);
  }
 }
}
