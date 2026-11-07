<?php
namespace App\Services;
use Carbon\CarbonImmutable;
class PromotionEligibility
{
 public const NEAR_DAYS=30;
 public static function reason(array $product,string $type,?string $date=null): ?string
 {
  if(($product['status']??'Active')!=='Active'||!empty($product['archivedAt']))return 'This product is inactive or archived.';
  $today=CarbonImmutable::parse($date??now()->timezone('Asia/Manila')->toDateString(),'Asia/Manila')->startOfDay();
  $expiry=array_key_exists('promotionExpiry',$product)?$product['promotionExpiry']:($product['expiry']??null);
  if($expiry){
   $expires=CarbonImmutable::parse($expiry,'Asia/Manila')->startOfDay();
   if($expires->lessThanOrEqualTo($today))return 'This product is expired and cannot be selected for promotion.';
   if($expires->equalTo($today->addDay()))return 'This product expires tomorrow and is no longer eligible for promotion.';
  }elseif($type==='Near-Expiry')return 'Near-expiry promotions require stock with a recorded expiration date.';
  if(($product['expiredStock']??0)>0&&($product['sellableStock']??0)<=0)return 'This product has no unexpired stock available for promotion.';
  return null;
 }
}
