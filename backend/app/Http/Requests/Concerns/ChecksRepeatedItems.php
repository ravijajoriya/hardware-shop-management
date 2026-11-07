<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Each item may be on a bill or delivery once in each unit: wire can be on the same bill as
 * "1 coil" and as "5 m", but not as "5 m" twice; that is one line with a bigger quantity.
 */
trait ChecksRepeatedItems
{
    /**
     * @param  string  $unitField  The item field saying how it is counted: "pack" or "loose".
     * @param  string  $default  How an item is counted when the field is left out.
     */
    protected function rejectRepeatedItems(Validator $validator, string $unitField, string $default, string $message): void
    {
        $seen = [];

        foreach ((array) $this->input('items') as $index => $item) {
            $id = is_array($item) ? ($item['product_variant_id'] ?? null) : null;

            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }

            $key = $id.'|'.($item[$unitField] ?? $default);

            if (isset($seen[$key])) {
                $validator->errors()->add("items.{$index}.product_variant_id", $message);
            }

            $seen[$key] = true;
        }
    }
}
