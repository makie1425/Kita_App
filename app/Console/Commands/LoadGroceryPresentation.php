<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\{WorkspaceRead, FifoInventory, StockMovement, SaleCheckout};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Auth};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LoadGroceryPresentation extends Command
{
    protected $signature = 'presentation:load-grocery {admin} {manager} {--apply}';
    protected $description = 'Load an isolated, repeat-safe grocery presentation dataset into a verified manager workspace';

    public function handle(): int
    {
        if ($this->option('apply')) {
            try { $this->provisionUnassignedManager(); }
            catch (\Throwable $error) { $this->error($error->getMessage()); return self::FAILURE; }
        }
        if ($this->call('presentation:check-accounts', ['admin'=>$this->argument('admin'),'manager'=>$this->argument('manager')]) !== 0) return self::FAILURE;
        $manager = User::whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($this->argument('manager')))])->firstOrFail();
        $workspace = WorkspaceRead::forUser($manager);
        $prefix = 'GR'.$workspace;
        if (!$this->option('apply')) {
            $this->info("Preview: workspace {$workspace}; 50 products, 150 batches, 150 cash sales across 2024–2026, 151 purchase orders. Use --apply to load.");
            return self::SUCCESS;
        }
        $previous = Auth::user();
        $clock = Carbon::getTestNow();
        Auth::setUser($manager);
        try {
            $loaded = DB::transaction(function () use ($workspace, $prefix, $manager) {
                $this->line('Grocery import: waiting for workspace lock. Records become visible only after commit.');
                DB::table('workspaces')->where('id',$workspace)->lockForUpdate()->first();
                if (DB::table('transactions')->where('uuid',$prefix.'-2026-49')->exists()) {
                    $this->info('This dataset is already loaded. Nothing changed.'); return false;
                }
                // Refuse to merge synthetic history into an operating catalog.
                foreach (['products','transactions','purchase_orders','suppliers','categories','brands','subcategories'] as $table) {
                    if (DB::table($table)->where('workspace_id',$workspace)->exists()) throw new \RuntimeException('Target workspace already contains '.$table.'. No records changed; a separate empty workspace is required.');
                }
                $insert = fn($table,$row)=>DB::table($table)->insert($row+['workspace_id'=>$workspace]);
                $groups = [
                    ['Rice & Grains','Grains','Dinorado Rice|Jasmine Rice|Brown Rice|Glutinous Rice|Corn Grits',1000,'Gram',65],
                    ['Canned Goods','Canned Food','Sardines in Tomato Sauce|Corned Beef|Tuna Flakes|Pork Luncheon Meat|Baked Beans',155,'Gram',38],
                    ['Beverages','Drinks','Orange Juice|Apple Juice|Pineapple Juice|Iced Tea|Calamansi Juice',1000,'Milliliter',75],
                    ['Dairy','Milk','Full Cream Milk|Low Fat Milk|Chocolate Milk|Evaporated Milk|Condensed Milk',250,'Milliliter',45],
                    ['Noodles & Pasta','Pasta','Spaghetti|Elbow Macaroni|Penne Pasta|Rice Vermicelli|Egg Noodles',500,'Gram',60],
                    ['Cooking Essentials','Condiments','Soy Sauce|Cane Vinegar|Fish Sauce|Oyster Sauce|Tomato Sauce',250,'Milliliter',35],
                    ['Snacks','Biscuits','Butter Cookies|Cream Crackers|Chocolate Wafers|Oat Cookies|Cheese Crackers',150,'Gram',32],
                    ['Personal Care','Soap','Aloe Bath Soap|Milk Bath Soap|Oatmeal Bath Soap|Lavender Bath Soap|Charcoal Bath Soap',90,'Gram',40],
                    ['Household Supplies','Cleaning','Lemon Dishwashing Liquid|Laundry Liquid|Fabric Conditioner|Floor Cleaner|Glass Cleaner',500,'Milliliter',85],
                    ['Baking Supplies','Ingredients','All Purpose Flour|Bread Flour|White Sugar|Brown Sugar|Corn Starch',500,'Gram',48],
                ];
                $products=[];
                $this->line('Grocery import: creating catalog.');
                foreach ($groups as $g=>$group) {
                    [$category,$sub,$names,$size,$unit,$price]=$group;
                    $insert('categories',['name'=>$category,'status'=>'Active','classification'=>'Non-Perishable']);
                    $brand=DB::table('brands')->insertGetId(['name'=>['Harvest Lane','Coastal Pantry','Orchard Grove','Meadow Fresh','Kitchen Table','Pantry Select','Golden Oven','Pure Meadow','Bright Home','Bakers Corner'][$g],'status'=>'Active','workspace_id'=>$workspace]);
                    $subcategory=DB::table('subcategories')->insertGetId(['category'=>$category,'name'=>$sub,'status'=>'Active','workspace_id'=>$workspace]);
                    $supplier=(int) DB::table('suppliers')->max('id')+1;
                    $insert('suppliers',['id'=>$supplier,'name'=>['Southern Grain Trading','Coastal Foods Supply','Orchard Beverage Distribution','Meadow Dairy Trading','Golden Wheat Supply','Pantry Goods Trading','Sunrise Snack Distribution','Wellness Goods Supply','Bright Household Trading','Baking Essentials Supply'][$g],'status'=>'Active','category'=>$category,'email'=>'orders'.$g.'@example.invalid']);
                    foreach(explode('|',$names) as $n=>$name){
                        $id=(int)DB::table('products')->max('id')+1;$cost=$price+$n*3;
                        $insert('products',['id'=>$id,'name'=>$name,'category'=>$category,'manufacturer'=>'Regional Consumer Goods','brandId'=>$brand,'subcategoryId'=>$subcategory,'size'=>$size,'sizeUnit'=>$unit,'status'=>'Active','unit'=>'Piece','stockUnit'=>'Piece','purchaseUnit'=>'Piece','conversionFactor'=>1,'stock'=>0,'minStock'=>10,'unitPrice'=>$cost,'price'=>round($cost*1.25,2),'vatClass'=>'VATable','supplierId'=>$supplier,'barcode'=>$prefix.'-'.str_pad((string)count($products),4,'0',STR_PAD_LEFT)]);
                        $insert('supplier_products',['supplierId'=>$supplier,'productId'=>$id,'costPrice'=>$cost,'preferred'=>true]);
                        $products[]=compact('id','name','supplier','cost');
                    }
                }
                $this->line('Grocery import: 50 products prepared; writing receipt and sale history. Not committed yet.');
                foreach ([2024,2025,2026] as $year) {
                    foreach ($products as $i=>$p) {
                        $date=Carbon::create($year,1+intdiv($i,5),2+$i%5,9,15,0,config('app.timezone'));
                        Carbon::setTestNow($date);
                        $po=$prefix.'-PO-'.$year.'-'.$i;$receipt=$prefix.'-RC-'.$year.'-'.$i;
                        // Historical batches sell out; current-year batches retain usable stock.
                        $qty=$year===2026?30+$i%8:2+$i%4;$value=$qty*$p['cost'];
                        $insert('purchase_orders',['id'=>$po,'supplierId'=>$p['supplier'],'status'=>'Fully Received','created'=>$date->toDateString(),'orderedValue'=>$value,'deliveredValue'=>$value,'invoicedValue'=>$value,'paidValue'=>0,'outstandingValue'=>$value,'cancelledValue'=>0,'receivingRecordIds'=>json_encode([$receipt])]);
                        $insert('purchase_order_lines',['poId'=>$po,'productId'=>$p['id'],'name'=>$p['name'],'orderedQty'=>$qty,'deliveredQty'=>$qty,'unitCost'=>$p['cost']]);
                        $insert('receiving_records',['id'=>$receipt,'poId'=>$po,'date'=>$date->toDateString(),'deliveryStatus'=>'Fully Received','discrepancy'=>false]);
                        $lot='LOT-'.$year.'-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
                        $expiry=$date->copy()->addYears(2)->toDateString();
                        $insert('receiving_record_lines',['receivingId'=>$receipt,'productId'=>$p['id'],'name'=>$p['name'],'poQty'=>$qty,'deliveredQty'=>$qty,'invoiceQty'=>$qty,'conditionText'=>'Good','unitCost'=>$p['cost'],'batchNumber'=>$lot,'expiryDate'=>$expiry]);
                        $before=(int)DB::table('products')->where('id',$p['id'])->value('stock');
                        FifoInventory::receive($p['id'],$qty,$p['cost'],$date->toDateString(),$receipt,$p['supplier'],'purchase_receiving',$lot,$expiry,lot:$lot);
                        DB::table('products')->where('id',$p['id'])->update(['stock'=>$before+$qty]);
                        StockMovement::record($p['id'],$before,$before+$qty,'purchase_receiving',$receipt,$manager);
                        Carbon::setTestNow($date->copy()->addDays(3)->setTime(10+$i%9,($i*7)%60));
                        $request=Request::create('/','POST');$request->setUserResolver(fn()=>$manager);
                        $data=['uuid'=>$prefix.'-'.$year.'-'.$i,'items'=>[['productId'=>$p['id'],'qty'=>2+$i%4]]];
                        $sale=SaleCheckout::prepare($request,$data);
                        SaleCheckout::save($request,$data,$sale,'Unused','Cash',(int)(ceil($sale['total']/10000)*10000),notify:false);
                        if (($i+1)%10===0) $this->line('Grocery import: '.$year.' history '.($i+1).'/50 prepared. Not committed yet.');
                    }
                }
                $insert('purchase_orders',['id'=>$prefix.'-PO-CANCEL','supplierId'=>$products[0]['supplier'],'status'=>'Cancelled','created'=>'2026-10-20','orderedValue'=>650,'cancelledValue'=>650,'deliveredValue'=>0,'invoicedValue'=>0,'paidValue'=>0,'outstandingValue'=>0,'supplierCallback'=>'Supplier could not fulfill requested quantities.']);
                $insert('purchase_order_lines',['poId'=>$prefix.'-PO-CANCEL','productId'=>$products[0]['id'],'name'=>$products[0]['name'],'orderedQty'=>10,'deliveredQty'=>0,'unitCost'=>65]);
                return true;
            });
            if ($loaded) $this->info('Loaded 50 products, 150 batches, 150 cash sales and 151 purchase orders. Database commit completed. No external messages or payments sent.');
        } catch (\Throwable $e) {
            $this->error('Import rolled back: '.$e->getMessage());return self::FAILURE;
        } finally {
            Carbon::setTestNow($clock);
            if($previous)Auth::setUser($previous);else Auth::forgetUser();
        }
        return self::SUCCESS;
    }

    private function provisionUnassignedManager(): void
    {
        DB::transaction(function () {
            $accounts=[];
            foreach(['admin','manager'] as $role){
                $matches=User::whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($this->argument($role)))])->lockForUpdate()->get();
                if($matches->count()!==1 || $matches->first()->normalizedRole()!==$role || !$matches->first()->isActive())
                    throw new \RuntimeException('Workspace setup refused: account role or status does not match.');
                $accounts[$role]=$matches->first();
            }
            $admin=$accounts['admin'];$manager=$accounts['manager'];
            if($manager->workspace_id)return;
            if(DB::table('workspace_memberships')->where('user_id',$manager->id)->where('user_role','manager')->exists())
                throw new \RuntimeException('Manager has existing memberships but no selected workspace. Review assignments before importing.');
            if($manager->created_by_user_id && ((int)$manager->created_by_user_id!==(int)$admin->id || $manager->created_by_user_role!=='admin'))
                throw new \RuntimeException('Manager creator conflicts with the configured Admin. No ownership changed.');
            $organization=null;
            if($admin->workspace_id){
                if(!in_array((int)$admin->workspace_id,\App\Services\WorkspaceAccess::accountWorkspaceIds($admin),true))
                    throw new \RuntimeException('Admin workspace membership requires review.');
                $organization=DB::table('workspaces')->where('id',$admin->workspace_id)->value('organization_id');
                if(!$organization)throw new \RuntimeException('Admin workspace does not exist.');
            } elseif(DB::table('workspace_memberships')->where('user_id',$admin->id)->where('user_role','admin')->exists()) {
                throw new \RuntimeException('Admin has memberships but no selected workspace. Review assignments first.');
            }
            if(!$organization)$organization=DB::table('organizations')->insertGetId(['name'=>$admin->name.' Grocery','created_at'=>now(),'updated_at'=>now()]);
            $workspace=DB::table('workspaces')->insertGetId(['organization_id'=>$organization,'name'=>$manager->name.' Grocery','created_at'=>now(),'updated_at'=>now()]);
            foreach($accounts as $role=>$user){
                DB::table('workspace_memberships')->insert(['workspace_id'=>$workspace,'user_id'=>$user->id,'user_role'=>$role,'active'=>true,'can_manage_accounts'=>true,'created_at'=>now(),'updated_at'=>now()]);
                if(!$user->workspace_id)$user->forceFill(['workspace_id'=>$workspace])->save();
            }
            $this->info("Created empty grocery workspace {$workspace} for the configured Admin and Manager. Historical records were not reassigned.");
        });
    }
}
