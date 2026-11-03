<?php

namespace App\Enums;

/**
 * The part of the shop an activity belongs to, for filtering the log.
 */
enum ActivitySection: string
{
    case Logins = 'logins';
    case Bills = 'bills';
    case Payments = 'payments';
    case Customers = 'customers';
    case Stock = 'stock';
    case Products = 'products';
    case Categories = 'categories';
    case Brands = 'brands';

    public function label(): string
    {
        return match ($this) {
            self::Logins => 'Logins',
            self::Bills => 'Bills',
            self::Payments => 'Payments',
            self::Customers => 'Customers',
            self::Stock => 'Stock',
            self::Products => 'Products',
            self::Categories => 'Categories',
            self::Brands => 'Brands',
        };
    }
}
