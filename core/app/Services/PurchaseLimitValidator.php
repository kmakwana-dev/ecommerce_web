<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Static anti-duplicate purchase rules for restricted e-commerce products.
 *
 * Rules are intentionally hard-coded so they can be changed quickly without
 * adding admin settings or database migrations.
 */
class PurchaseLimitValidator
{
    // Maximum units of the same product allowed for one email address.
    private const MAX_UNITS_PER_PRODUCT_PER_EMAIL = 1;

    /**
     * Validate the cart against previously completed purchases for this email.
     *
     * This checks the product level, not the variant level, so changing a
     * colour/size cannot be used to bypass the same-product limit.
     */
    public static function validateCart($cartData, ?string $email): void
    {
        $email = self::normalizeEmail($email);

        if (!$email) {
            return;
        }

        $productQuantities = [];

        foreach ($cartData as $cartItem) {
            $productId = (int) $cartItem->product_id;
            $quantity  = (int) $cartItem->quantity;

            if ($productId <= 0 || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'error' => 'Invalid product quantity detected. Please refresh your cart and try again.',
                ]);
            }

            $productQuantities[$productId] = ($productQuantities[$productId] ?? 0) + $quantity;
        }

        foreach ($productQuantities as $productId => $cartQuantity) {
            if ($cartQuantity > self::MAX_UNITS_PER_PRODUCT_PER_EMAIL) {
                throw ValidationException::withMessages([
                    'error' => 'Only ' . self::MAX_UNITS_PER_PRODUCT_PER_EMAIL . ' unit of the same product can be purchased using one email address.',
                ]);
            }

            $alreadyPurchased = self::successfulQuantityForProductAndEmail($productId, $email);
            $remaining        = self::MAX_UNITS_PER_PRODUCT_PER_EMAIL - $alreadyPurchased;

            if ($cartQuantity > $remaining) {
                throw ValidationException::withMessages([
                    'error' => 'Purchase limit reached for this product. This email address has already purchased the maximum allowed quantity. Please use another email address for additional quantity.',
                ]);
            }
        }
    }

    /**
     * Return the total quantity already purchased by this email for a product.
     *
     * Only valid paid/COD orders count. Cancelled and returned orders do not
     * consume the purchase allowance.
     */
    private static function successfulQuantityForProductAndEmail(int $productId, string $email): int
    {
        $query = Order::query()
            ->join('order_details', 'orders.id', '=', 'order_details.order_id')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->leftJoin('guests', 'orders.guest_id', '=', 'guests.id')
            ->where('order_details.product_id', $productId)
            ->whereNotIn('orders.status', [Status::ORDER_CANCELED, Status::ORDER_RETURNED])
            ->where(function ($query) {
                $query->where('orders.payment_status', Status::PAYMENT_SUCCESS)
                    ->orWhere('orders.is_cod', Status::YES);
            })
            ->where(function ($query) use ($email) {
                $query->whereRaw('LOWER(TRIM(users.email)) = ?', [$email])
                    ->orWhereRaw('LOWER(TRIM(guests.email)) = ?', [$email]);
            });

        return (int) $query->sum('order_details.quantity');
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if (!$email) {
            return null;
        }

        $email = strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
