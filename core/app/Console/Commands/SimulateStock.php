<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SimulateStock extends Command
{
    protected $signature = 'stock:simulate';
    protected $description = 'Simulate buyer purchases, restocks, and maintain 4-8 out-of-stock published products';

    public function handle()
    {
        $this->info('--- Starting Stock Simulation (Published Products Only) ---');

        // 1. Pick 3-5 random published products and decrease stock by 1
        $purchasedProducts = DB::table('products')
            ->where('is_published', 1)
            ->where('in_stock', '>', 1)
            ->inRandomOrder()
            ->limit(rand(3, 5))
            ->get(['id', 'name', 'in_stock']);

        foreach ($purchasedProducts as $product) {
            $newStock = $product->in_stock - 1;
            DB::table('products')->where('id', $product->id)->update(['in_stock' => $newStock]);
            $this->line("<comment>[Simulated Sale]</comment> {$product->name} (Stock: {$product->in_stock} -> {$newStock})");
        }

        // 2. Decrease stock on random size variants for published products
        $variantIds = DB::table('product_variants as pv')
            ->join('products as p', 'pv.product_id', '=', 'p.id')
            ->where('p.is_published', 1)
            ->where('pv.is_published', 1)
            ->where('pv.in_stock', '>', 1)
            ->inRandomOrder()
            ->limit(5)
            ->pluck('pv.id');

        if ($variantIds->isNotEmpty()) {
            DB::table('product_variants')
                ->whereIn('id', $variantIds)
                ->decrement('in_stock', 1);
        }

        // 3. Count current Out-Of-Stock published products
        $currentOos = DB::table('products')
            ->where('is_published', 1)
            ->where('in_stock', 0)
            ->count();

        // Target: maintain between 4 and 8 products out of stock
        $targetOos = rand(5, 7);
        $this->info("Current Out-of-Stock count: {$currentOos} (Target: {$targetOos})");

        if ($currentOos < $targetOos) {
            $diff = $targetOos - $currentOos;
            $oosItems = DB::table('products')
                ->where('is_published', 1)
                ->where('in_stock', '>', 0)
                ->inRandomOrder()
                ->limit($diff)
                ->get(['id', 'name']);

            foreach ($oosItems as $item) {
                DB::table('products')->where('id', $item->id)->update(['in_stock' => 0]);
                DB::table('product_variants')->where('product_id', $item->id)->update(['in_stock' => 0]);
                $this->line("<error>[Marked Out of Stock]</error> {$item->name}");
            }
        } elseif ($currentOos > $targetOos) {
            $diff = $currentOos - $targetOos;
            $restockItems = DB::table('products')
                ->where('is_published', 1)
                ->where('in_stock', 0)
                ->inRandomOrder()
                ->limit($diff)
                ->get(['id', 'name']);

            foreach ($restockItems as $item) {
                $restockQty = rand(6, 15);
                DB::table('products')->where('id', $item->id)->update(['in_stock' => $restockQty]);
                DB::table('product_variants')->where('product_id', $item->id)->update(['in_stock' => rand(4, 10)]);
                $this->line("<info>[Restocked Product]</info> {$item->name} (New Stock: {$restockQty})");
            }
        }

        $this->info('--- Stock Simulation Completed Successfully ---');
        return Command::SUCCESS;
    }
}
