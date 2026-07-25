import { useState } from 'react';

export type ClientTableMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type ClientTable<T> = {
    search: string;
    setSearch: (value: string) => void;
    filtered: T[];
    paged: T[];
    meta: ClientTableMeta;
    setPage: (page: number) => void;
    setLimit: (limit: number) => void;
};

/**
 * Client-side search + pagination over an already-loaded row set — the finance-module
 * datatable behaviour for lists small enough to ship whole (a student's statement, a
 * decision queue). `searchOf` returns the strings a row is matched against (case-insensitive
 * substring). No money arithmetic here — it only filters and slices rows the API already
 * computed; amounts are still rendered via formatNaira by the caller.
 */
export function useClientTable<T>(
    rows: T[],
    searchOf: (row: T) => (string | null | undefined)[],
    perPage = 10,
): ClientTable<T> {
    const [search, setSearchState] = useState('');
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(perPage);

    const query = search.trim().toLowerCase();
    const filtered =
        query === ''
            ? rows
            : rows.filter((row) =>
                  searchOf(row).some((field) =>
                      (field ?? '').toLowerCase().includes(query),
                  ),
              );

    const lastPage = Math.max(1, Math.ceil(filtered.length / limit));
    const currentPage = Math.min(page, lastPage);
    const paged = filtered.slice(
        (currentPage - 1) * limit,
        currentPage * limit,
    );

    return {
        search,
        setSearch: (value) => {
            setSearchState(value);
            setPage(1);
        },
        filtered,
        paged,
        meta: {
            current_page: currentPage,
            last_page: lastPage,
            per_page: limit,
            total: filtered.length,
        },
        setPage,
        setLimit: (next) => {
            setPage(1);
            setLimit(next);
        },
    };
}
