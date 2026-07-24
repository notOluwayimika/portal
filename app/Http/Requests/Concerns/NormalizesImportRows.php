<?php

namespace App\Http\Requests\Concerns;

trait NormalizesImportRows
{
    /**
     * Coerce numeric spreadsheet cells to strings before validation.
     *
     * A client-side xlsx/csv parser types a bare-digit cell (an admission or
     * staff number like `20251237`) as a JSON number, which then fails a
     * `string` rule with "the field must be a string" even though it is a
     * perfectly valid identifier. Every per-row field in these imports is a
     * string or a date (dates arrive as strings), and the row-level `array`
     * rule already rejects non-array rows — so stringifying int/float leaves is
     * safe and turns `20251237` into the `"20251237"` the column stores.
     *
     * @param  string  $key  the top-level array of rows, e.g. `students` / `teachers`
     */
    protected function stringifyNumericRowCells(string $key): void
    {
        $rows = $this->input($key);

        if (! is_array($rows)) {
            return;
        }

        $this->merge([
            $key => array_map(
                fn ($row) => is_array($row)
                    ? array_map(
                        fn ($value) => is_int($value) || is_float($value) ? (string) $value : $value,
                        $row,
                    )
                    : $row,
                $rows,
            ),
        ]);
    }
}
