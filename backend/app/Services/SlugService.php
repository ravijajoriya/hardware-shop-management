<?php

namespace App\Services;

use Illuminate\Support\Str;

class SlugService
{
    /**
     * Decide the slug to save: the one given, the current one when an update
     * leaves it out, or otherwise a slug built from the name that is still free.
     *
     * @param  array<string, mixed>  $data  Validated input with "name" and possibly "slug".
     * @param  string|null  $currentSlug  The record's slug when updating, null when creating.
     * @param  callable(string): bool  $isTaken
     */
    public function resolve(array $data, ?string $currentSlug, callable $isTaken, string $fallback): string
    {
        if (filled($data['slug'] ?? null)) {
            return $data['slug'];
        }

        if ($currentSlug !== null && ! array_key_exists('slug', $data)) {
            return $currentSlug;
        }

        $base = Str::slug($data['name']) ?: $fallback;
        $slug = $base;

        for ($suffix = 2; $isTaken($slug); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
