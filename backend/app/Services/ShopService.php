<?php

namespace App\Services;

class ShopService
{
    /**
     * The shop's name, address and phone, as printed at the top of a bill.
     *
     * @return array{name: string, address: string|null, phone: string|null}
     */
    public function details(): array
    {
        return [
            'name' => config('shop.name'),
            'address' => config('shop.address'),
            'phone' => config('shop.phone'),
        ];
    }
}
