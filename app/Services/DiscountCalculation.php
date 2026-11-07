<?php
namespace App\Services;
class DiscountCalculation
{
 public static function line(int $base, int $effective, float $promotionRate, string $type): array
 {
  $promotionRate=$type==='employee'?0:$promotionRate;
  $basisPoints=(int)round($promotionRate*100);
  $afterPromotion=intdiv($effective*(10000-$basisPoints)+5000,10000);
  $rate=in_array($type,['senior','pwd','employee'],true)?10:0;
  $net=intdiv($afterPromotion*(100-$rate)+50,100);
  return ['net'=>$net,'promotionDiscount'=>$effective-$afterPromotion,'customerDiscount'=>$afterPromotion-$net,'overrideDiscount'=>$base-$effective];
 }
}
