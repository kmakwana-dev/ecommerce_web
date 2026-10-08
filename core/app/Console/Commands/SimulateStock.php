<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SimulateStock extends Command
{
    protected $signature = 'stock:simulate';
    protected $description = 'Simulate dynamic buyer purchases, restocks, and maintain 4-8 out-of-stock published products';

    public function handle()
    {
        $this->info('--- Starting Dynamic Stock Simulation ---');

        // 1. SIMULATE PURCHASES: Decrease stock on 2-4 random products
        $purchasedProducts = DB::table('products')
            ->where('is_published', 1)
            ->where('in_stock', '>', 2)
            ->inRandomOrder()
            ->limit(rand(2, 4))
            ->get(['id', 'name', 'in_stock']);

        foreach ($purchasedProducts as $product) {
            $newStock = $product->in_stock - 1;
            DB::table('products')->where('id', $product->id)->update(['in_stock' => $newStock]);
            $this->line("<comment>[Sale - Decreased]</comment> {$product->name} (Stock: {$product->in_stock} -> {$newStock})");
        }

        // 2. SIMULATE RESTOCKS: Increase stock (+3 to +8) on 2-3 products
        $restockProducts = DB::table('products')
            ->where('is_published', 1)
            ->where('in_stock', '>', 0)
            ->where('in_stock', '<', 15)
            ->inRandomOrder()
            ->limit(rand(2, 3))
            ->get(['id', 'name', 'in_stock']);

        foreach ($restockProducts as $product) {
            $addStock = rand(3, 8);
            $newStock = $product->in_stock + $addStock;
            DB::table('products')->where('id', $product->id)->update(['in_stock' => $newStock]);
            $this->line("<info>[Restock - Increased]</info> {$product->name} (Stock: {$product->in_stock} -> {$newStock})");
        }

        // 3. Decrement a few random size variants
        $variantIds = DB::table('product_variants as pv')
            ->join('products as p', 'pv.product_id', '=', 'p.id')
            ->where('p.is_published', 1)
            ->where('pv.is_published', 1)
            ->where('pv.in_stock', '>', 1)
            ->inRandomOrder()
            ->limit(4)
            ->pluck('pv.id');

        if ($variantIds->isNotEmpty()) {
            DB::table('product_variants')->whereIn('id', $variantIds)->decrement('in_stock', 1);
        }

        // 4. MAINTAIN 4 TO 8 OUT-OF-STOCK PRODUCTS
        $currentOos = DB::table('products')->where('is_published', 1)->where('in_stock', 0)->count();
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
                $this->line("<error>[Now Out of Stock]</error> {$item->name}");
            }
        } elseif ($currentOos > $targetOos) {
            $diff = $currentOos - $targetOos;
            $backInStockItems = DB::table('products')
                ->where('is_published', 1)
                ->where('in_stock', 0)
                ->inRandomOrder()
                ->limit($diff)
                ->get(['id', 'name']);

            foreach ($backInStockItems as $item) {
                $qty = rand(6, 14);
                DB::table('products')->where('id', $item->id)->update(['in_stock' => $qty]);
                DB::table('product_variants')->where('product_id', $item->id)->update(['in_stock' => rand(4, 10)]);
                $this->line("<info>[Back In Stock]</info> {$item->name} (Restocked with {$qty} units)");
            }
        }

        $this->info('--- Stock Simulation Completed Successfully ---');
        return Command::SUCCESS;
    }
}
