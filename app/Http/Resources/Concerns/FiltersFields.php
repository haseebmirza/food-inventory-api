<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Allows clients to request a subset of fields via ?fields=id,name,unit.
 *
 * Resources using this trait must define an ALLOWED_FIELDS constant
 * listing every field the client may request. The 'id' field is
 * always included so that resources remain identifiable.
 */
trait FiltersFields
{
    /**
     * Parse the requested fields from the query string.
     *
     * @return string[]|null null means "return everything"
     */
    public static function requestedFields(Request $request): ?array
    {
        $raw = $request->query('fields');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $allowed = defined(static::class.'::ALLOWED_FIELDS') ? static::ALLOWED_FIELDS : [];
        $requested = array_intersect(
            array_map('trim', explode(',', $raw)),
            $allowed
        );

        // Always include 'id' so the resource is identifiable.
        return array_values(array_unique(['id', ...$requested]));
    }

    /**
     * Filter a toArray result to only the requested fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function filterFields(array $data, Request $request): array
    {
        $fields = static::requestedFields($request);

        if ($fields === null) {
            return $data;
        }

        return array_intersect_key($data, array_flip($fields));
    }
}
