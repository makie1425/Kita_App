<?php
/** Builds a NEW isolated SQLite artifact. Never connects to or clears the configured live database. */
use App\Http\Controllers\{CategoryController,ProductController,PurchasingController,OperationsController,TransactionController,InventoryWorkflowController};
use App\Models\User;
use App\Services\{StockReceiptWorkflow,PurchaseWorkflow};
use Illuminate\Http\Request;
use Illuminate\Support\{Carbon,Str};
use Illuminate\Support\Facades\{DB,Artisan,Hash,Http,Mail};

require dirname(__DIR__).'/vendor/autoload.php';
$root=dirname(__DIR__);
$anchor=Carbon::parse($argv[1]??(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),'Asia/Manila')->startOfDay();
$dir=$root.'/storage/app/private/sample-data/'.$anchor->format('Ymd').'-'.bin2hex(random_bytes(4));
if(!mkdir($dir,0700,true)) throw new RuntimeException('Cannot create artifact directory.');
$dbFile=$dir.'/replacement.sqlite';
fclose(fopen($dbFile,'x'));
foreach(['APP_ENV'=>'local','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$dbFile,'DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'log','APP_CONFIG_CACHE'=>$dir.'/unused-config.php'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$dbFile,'database.connections.sqlite.url'=>null,'cache.default'=>'array','mail.default'=>'log']);
DB::purge('sqlite');
Http::preventStrayRequests(); Mail::fake();
if(Artisan::call('migrate',['--database'=>'sqlite','--force'=>true])!==0) throw new RuntimeException(Artisan::output());
$password=Str::random(32);
$actors=[];
foreach(['manager','admin','cashier','superadmin'] as $role){
    $u=User::create(['name'=>'Sample '.ucfirst($role),'email'=>$role.'@sample.invalid','password'=>$password,'role'=>$role,'status'=>'Active','refundLimit'=>10000,'createdBy'=>'Generated sample dataset']);
    if($role==='manager'){$u->forceFill(['approval_pin'=>Hash::make('2468')])->save();}
    $actors[$role]=$u;
}
function callSample(string $class,string $method,array $data=[],string $role='manager',?string $id=null):array {
    global $actors;
    $r=Request::create('/sample','POST',$data);$r->headers->set('Accept','application/json');$r->setUserResolver(fn()=>$actors[$role]);
    try{$response=$id===null?app($class)->$method($r):app($class)->$method($r,$id);return $response->getData(true);}
    catch(\Illuminate\Validation\ValidationException $e){throw new RuntimeException($class.'::'.$method.' '.json_encode($e->errors()));}
}
function sampleTime(Carbon $date):void {Carbon::setTestNow($date);}
$catalog=[];$scenarios=[];
sampleTime($anchor->copy()->subYear()->subMonth()->startOfMonth()->setTime(8,0));
foreach(['Beverages','Dairy','Bakery','Snacks','Groceries','Household','Personal Care'] as $category){
    callSample(CategoryController::class,'store',['name'=>$category,'classification'=>in_array($category,['Dairy','Bakery'])?'Perishable':'Non-Perishable']);
    DB::table('subcategories')->insert(['category'=>$category,'name'=>'General '.$category,'status'=>'Active']);
}
$suppliers=[];
foreach(['Metro Beverage Supply','Fresh Valley Foods','Golden Pantry Wholesale','Home Essentials Trading'] as $i=>$name){
    $suppliers[$i]=callSample(PurchasingController::class,'supplier',['name'=>$name,'contact'=>'Sample Supplier Contact','email'=>'supplier'.($i+1).'@example.com','phone'=>'','address'=>'Sample supplier address, Philippines'])['supplier']['id'];
}
$specs=[
 ['Coke 1L','Beverages',0,55,75,20],['Sprite 1L','Beverages',0,50,70,15],['Bottled Water 500mL','Beverages',0,8,15,30],['Orange Juice 250mL','Beverages',0,18,28,15],
 ['Potato Chips 60g','Snacks',2,18,30,15],['Chocolate Bar 40g','Snacks',2,20,35,12],['Cream Crackers 250g','Snacks',2,28,42,12],['Instant Coffee 3-in-1','Groceries',2,6,10,30],
 ['Canned Tuna 155g','Groceries',2,28,40,12],['Instant Noodles 60g','Groceries',2,9,15,24],['White Sugar 1kg','Groceries',2,62,80,10],['Cooking Oil 1L','Groceries',2,75,100,10],
 ['Laundry Detergent 500g','Household',3,35,50,10],['Dishwashing Liquid 250mL','Household',3,20,35,10],['Bath Soap 90g','Personal Care',3,22,35,10],['Toothpaste 100g','Personal Care',3,38,55,10],
 ['Fresh Milk 1L','Dairy',1,65,90,10],['Strawberry Yogurt 150g','Dairy',1,25,40,10],['Whole Wheat Bread','Bakery',1,45,65,8],['Cheese Slices 200g','Dairy',1,70,95,8],
 ['Butter Cookies 200g','Snacks',2,35,50,10],['Tissue Paper 100 Sheets','Household',3,12,20,10],['Hand Sanitizer 100mL','Personal Care',3,25,40,10],['Sparkling Water 330mL','Beverages',0,20,32,10]
];
foreach($specs as $i=>[$name,$category,$supplier,$cost,$price,$minimum]){
    $brand=DB::table('brands')->insertGetId(['name'=>'Sample Brand '.($i+1),'status'=>'Active']);
    $p=callSample(ProductController::class,'store',['name'=>$name,'category'=>$category,'manufacturer'=>'Sample Manufacturer '.($supplier+1),'brandId'=>$brand,'subcategoryId'=>DB::table('subcategories')->where('category',$category)->value('id'),'barcode'=>'SAMPLE-'.str_pad($i+1,5,'0',STR_PAD_LEFT),'stock'=>0,'minStock'=>$minimum,'unitPrice'=>$cost,'price'=>$price,'stockUnit'=>'Piece','purchaseUnit'=>'Case','conversionFactor'=>12,'supplierId'=>$suppliers[$supplier],'vatClass'=>'VATable'])['product'];
    $catalog[$i]=$p;
}
function sampleOrder(array $quantities,string $status='approved'):string {
    global $catalog;
    $lines=[];foreach($quantities as $index=>$qty){$p=$catalog[$index];$lines[]=['productId'=>$p['id'],'qty'=>$qty,'supplierId'=>$p['supplierId'],'unitCost'=>$p['unitPrice'],'orderUnit'=>'Piece'];}
    $id=callSample(OperationsController::class,'storeRequest',['category'=>$catalog[array_key_first($quantities)]['category'],'submissionKey'=>(string)Str::uuid(),'notes'=>'Generated sample data for presentation; not an actual supplier order.','expectedDeliveryDate'=>now()->toDateString(),'lines'=>$lines])['id'];
    if($status!=='pending')callSample(OperationsController::class,'updateRequest',['action'=>$status,'revision'=>0,'note'=>$status==='disapproved'?'Sample declined request: duplicate replenishment.':'Sample approval.'],'admin',$id);
    return $id;
}
function sampleEntry(string $po,int $index,int $delivered,int $damaged=0,string $type='current',?array $lots=null):array {
    global $catalog,$anchor;
    $p=$catalog[$index];$qty=$delivered-$damaged;
    return ['poId'=>$po,'version'=>(int)DB::table('purchase_orders')->where('id',$po)->value('receivingVersion'),'productId'=>$p['id'],'type'=>$type,'delivered'=>$delivered,'damaged'=>$damaged,'damageReason'=>$damaged?'Sample damaged packaging found during inspection.':null,'disposition'=>'return','unitCost'=>$p['unitPrice'],'lots'=>$lots??($qty?[['quantity'=>$qty,'supplierLot'=>'SUP-'.now()->format('Ymd').'-'.$p['id'],'expiryDate'=>in_array($p['category'],['Household','Personal Care'])?null:$anchor->copy()->addMonths(8)->toDateString()]]:[])];
}
function sampleReceipt(string $po,array $entries,bool $approve=false):array {
    $result=callSample(StockReceiptWorkflow::class,'submit',['requestKey'=>(string)Str::uuid(),'primaryPo'=>$po,'supplierId'=>(int)DB::table('purchase_orders')->where('id',$po)->value('supplierId'),'deliveryDate'=>now()->toDateString(),'supplierReference'=>'SAMPLE-'.now()->format('Ymd'),'notes'=>'Generated sample receipt. Any substitution or cost exception is for presentation only.','entries'=>$entries]);
    if($approve&&$result['status']==='Pending Approval'){
        callSample(StockReceiptWorkflow::class,'review',['action'=>'Approved','note'=>'Sample exception reviewed.'],'admin',$result['id']);
        callSample(StockReceiptWorkflow::class,'post',[],'manager',$result['id']);
    }
    return $result;
}
// Generate in chronological order so running stock balances agree with history dates.
$saleNumber=0;$events=[];
function sampleEvent(Carbon $date,callable $fn):void {global $events;$events[]=['date'=>$date,'fn'=>$fn,'sequence'=>count($events)];}
function sampleSale(array $quantities):string {
    global $catalog,$saleNumber;
    $items=[];foreach($quantities as $i=>$qty)$items[]=['productId'=>$catalog[$i]['id'],'qty'=>$qty];
    $id='SAMPLE-'.now()->format('Ymd').'-'.str_pad(++$saleNumber,5,'0',STR_PAD_LEFT);
    callSample(TransactionController::class,'store',['uuid'=>$id,'items'=>$items,'paymentMode'=>'Cash','tendered'=>10000],'cashier');return $id;
}
sampleEvent($anchor->copy()->subYear()->startOfMonth()->setTime(8,0),function(){
    $po=sampleOrder([0=>60,2=>80]);sampleReceipt($po,[sampleEntry($po,0,60),sampleEntry($po,2,80)]);
});
sampleEvent($anchor->copy()->subYear()->startOfMonth()->addDay()->setTime(12,0),fn()=>sampleSale([0=>6,2=>12]));
for($m=6;$m>=0;$m--){
    $start=$anchor->copy()->subMonthsNoOverflow($m)->startOfMonth();
    sampleEvent($start->copy()->setTime(8,0),function()use($catalog,$suppliers){
        foreach([0,2,3] as $supplierIndex){
            $q=[];foreach($catalog as $i=>$p)if($i<16&&(int)$p['supplierId']===$suppliers[$supplierIndex])$q[$i]=100;
            $po=sampleOrder($q);$entries=[];foreach($q as $i=>$qty)$entries[]=sampleEntry($po,$i,$qty);sampleReceipt($po,$entries);
        }
        echo 'Built receipts for '.now()->format('Y-m').PHP_EOL;
    });
    $last=$m===0?$anchor->day:$start->daysInMonth;
    foreach(range(1,$last) as $day){
        if($day%3!==1&&$day!==$last)continue;
        foreach([9,12,17,20] as $hour){
            $i=($day+$m+$hour)%16;$j=($i+5)%16;
            sampleEvent($start->copy()->day($day)->setTime($hour,($day*7)%60),fn()=>sampleSale([$i=>1+($hour+$day)%4,$j=>1+($day+$m)%2]));
        }
    }
}
sampleEvent($anchor->copy()->subDays(20)->setTime(8,30),function()use($anchor){
    foreach([16=>[-3,5],17=>[2,12],18=>[1,7],19=>[20,65]] as $i=>$days){
        $po=sampleOrder([$i=>30]);$lots=[];foreach($days as $n=>$offset)$lots[]=['quantity'=>15,'supplierLot'=>'EXP-'.$i.'-'.$n,'expiryDate'=>$anchor->copy()->addDays($offset)->toDateString(),'manufacturedDate'=>now()->subDays(2)->toDateString()];
        sampleReceipt($po,[sampleEntry($po,$i,30,0,'current',$lots)]);
    }
    foreach([20=>5,21=>4,22=>12] as $i=>$qty){$po=sampleOrder([$i=>$qty]);sampleReceipt($po,[sampleEntry($po,$i,$qty)]);}
});
$scenarios['out_of_stock_product']=$catalog[23]['name'];
sampleEvent($anchor->copy()->subDays(8)->setTime(10,0),function()use(&$old,&$scenarios){
    $old=sampleOrder([0=>50]);sampleReceipt($old,[sampleEntry($old,0,45,5)]);$scenarios['partial_with_5_missing_5_damaged']=$old;
});
sampleEvent($anchor->copy()->subDays(4)->setTime(10,0),function()use(&$old,&$scenarios){
    $new=sampleOrder([0=>30]);$combined=sampleReceipt($new,[sampleEntry($new,0,30),sampleEntry($old,0,5,0,'missing')]);
    $scenarios['combined_30_new_plus_5_missing']=['current_po'=>$new,'original_po'=>$old,'stock_receipt'=>$combined['id']];
});
sampleEvent($anchor->copy()->subDays(2)->setTime(11,0),function()use(&$old,&$scenarios,$catalog){
    $new=sampleOrder([1=>20]);sampleReceipt($new,[sampleEntry($new,1,20),sampleEntry($old,0,5,0,'damaged')]);
    $open=sampleOrder([0=>50,1=>30]);sampleReceipt($open,[sampleEntry($open,0,45,5),sampleEntry($open,1,30)]);$scenarios['unresolved_missing_and_damage']=$open;
    $sub=sampleOrder([0=>10]);sampleReceipt($sub,[sampleEntry($sub,0,8)]);
    $e=sampleEntry($sub,0,2,0,'missing');$e['substituteProductId']=$catalog[1]['id'];$e['substitutionReason']='Sample equal-or-lower-cost replacement authorized by supplier.';$e['unitCost']=$catalog[1]['unitPrice'];sampleReceipt($sub,[$e],true);$scenarios['approved_substitution']=$sub;
});
sampleEvent($anchor->copy()->setTime(8,0),function()use(&$scenarios,$catalog){
    $scenarios['ready_to_receive']=sampleOrder([0=>50,2=>60]);
    $scenarios['pending_purchase_approval']=sampleOrder([4=>50,5=>30],'pending');
    $scenarios['declined_purchase']=sampleOrder([12=>40],'disapproved');
    $po=sampleOrder([8=>20]);$e=sampleEntry($po,8,10);$e['unitCost']+=2;$scenarios['receipt_awaiting_cost_approval']=sampleReceipt($po,[$e])['id'];
    $batch=DB::table('inventory_batches')->where('productId',$catalog[22]['id'])->first();
    callSample(InventoryWorkflowController::class,'event',['requestKey'=>(string)Str::uuid(),'batchId'=>$batch->id,'quantity'=>$batch->quantityRemaining,'type'=>'Recall','reason'=>'Sample supplier quality recall.']);
    $batch=DB::table('inventory_batches')->where('productId',$catalog[12]['id'])->where('quantityRemaining','>',5)->first();
    callSample(InventoryWorkflowController::class,'event',['requestKey'=>(string)Str::uuid(),'batchId'=>$batch->id,'quantity'=>2,'type'=>'Write-off','reason'=>'Sample damaged packaging during shelf inspection.']);
});
sampleEvent($anchor->copy()->setTime(12,0),function()use(&$scenarios,$actors){
    $refund=sampleSale([0=>2]);callSample(TransactionController::class,'updateStatus',['action'=>'refund','reason'=>'Generated sample customer return.','manager_id'=>$actors['manager']->id,'manager_pin'=>'2468'],'cashier',$refund);
    $scenarios['refunded_sale']=$refund;
});
usort($events,fn($a,$b)=>$a['date']->timestamp<=>$b['date']->timestamp?:$a['sequence']<=>$b['sequence']);
foreach($events as $event){sampleTime($event['date']);($event['fn'])();}
sampleTime($anchor->copy()->endOfDay());
// Validate physical stock, allocations and the examples before export.
foreach(DB::table('products')->get() as $p){
    $balance=(int)DB::table('inventory_batches')->where('productId',$p->id)->sum('quantityRemaining');
    if($balance!==(int)$p->stock)throw new RuntimeException('Batch balance mismatch for product '.$p->id);
}
if(DB::table('inventory_batches')->where('quantityRemaining','<',0)->exists())throw new RuntimeException('Negative batch stock.');
$counts=[];foreach(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t)$counts[$t->name]=DB::table($t->name)->count();
$summary=['generated_sample_data'=>true,'anchor_date'=>$anchor->toDateString(),'database'=>$dbFile,'counts'=>$counts,'scenarios'=>$scenarios,'source_actors'=>collect($actors)->map(fn($u)=>['id'=>$u->id,'role'=>$u->role,'name'=>$u->name])->all(),'live_import'=>false];
file_put_contents($dir.'/manifest.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($dir.'/README.md',"# Generated KITA sample data\n\nAnchor date: ".$anchor->toDateString().". These are synthetic records, not actual sales or supplier deliveries.\n\nProducts: ".$counts['products']."; sales: ".$counts['transactions']."; stock receipts: ".$counts['stock_receipts'].".\n\nExisting live accounts must be preserved; sample accounts must NOT be imported. Map actor references before import.\n\nScenarios:\n\n```json\n".json_encode($scenarios,JSON_PRETTY_PRINT)."\n```\n");
Carbon::setTestNow();
echo 'VALIDATED_ARTIFACT='.$dir.PHP_EOL;
