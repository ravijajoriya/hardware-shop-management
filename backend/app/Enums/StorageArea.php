<?php

namespace App\Enums;

/**
 * The two places a hardware shop keeps its stock.
 */
enum StorageArea: string
{
    case Shop = 'shop';
    case Godown = 'godown';

    /**
     * The word a location code starts with, such as SHOP in SHOP-GF-R3-S2.
     */
    public function codeWord(): string
    {
        return strtoupper($this->value);
    }
}
