<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SimulateStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:simulate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate buyer purchases, restocks, and maintain 4-8 out-of-stock products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting stock simulation...');

        // 1. Simulate a few small buyer purchases (reduce stock by 1 on 4-6 random products)
        DB::statement("
            UPDATE products 
            SET in_stock = GREATEST(1, in_stock - 1) 
            WHERE is_published = 1 AND in_stock > 1 
            ORDER BY RAND() 
            LIMIT 5
        ");

        // Reduce stock on random size variants
        DB::statement("
            UPDATE product_variants 
            SET in_stock = GREATEST(1, in_stock - 1) 
            WHERE is_published = 1 AND in_stock > 1 
            ORDER BY RAND() 
            LIMIT 8
        ");

        // 2. Count current Out-Of-Stock products
        $currentOos = DB::table('products')
            ->where('is_published', 1)
            ->where('in_stock', 0)
            ->count();

        // Target: Keep between 4 and 8 products out of stock
        $targetOos = rand(5, 7);

        if ($currentOos < $targetOos) {
            $diff = $targetOos - $currentOos;
            DB::statement("
                UPDATE products 
                SET in_stock = 0 
                WHERE is_published = 1 AND in_stock > 0 
                ORDER BY RAND() 
                LIMIT {$diff}
            ");
            $this->info("Made {$diff} additional products out of stock.");
        } elseif ($currentOos > $targetOos) {
            $diff = $currentOos - $targetOos;
            DB::statement("
                UPDATE products 
                SET in_stock = FLOOR(5 + (RAND() * 12)) 
                WHERE is_published = 1 AND in_stock = 0 
                ORDER BY RAND() 
                LIMIT {$diff}
            ");
            $this->info("Restocked {$diff} products.");
        }

        // 3. Keep variant stocks in sync with product stock
        // For products that are out of stock, set all their variants to 0
        DB::statement("
            UPDATE product_variants pv 
            JOIN products p ON pv.product_id = p.id 
            SET pv.in_stock = 0 
            WHERE p.is_published = 1 AND p.in_stock = 0
        ");

        // For restocked products, ensure their variants also have stock
        DB::statement("
            UPDATE product_variants pv 
            JOIN products p ON pv.product_id = p.id 
            SET pv.in_stock = FLOOR(4 + (RAND() * 10)) 
            WHERE p.is_published = 1 AND p.in_stock > 0 AND pv.in_stock = 0
        ");

        $this->info('Stock simulation completed successfully.');
        return Command::SUCCESS;
    }
}
