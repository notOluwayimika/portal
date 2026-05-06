export interface SelectOption {
    label: string;
    value: string | number;
    icon?: string;
}

import { useEffect, useRef, useState } from 'react';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from './ui/select';

interface MultiSelectProps {
    options: SelectOption[];
    value: string | number;
    onChange: (value: string | number) => void;
    label: string;
}

export default function SingleSelect({
    options,
    value,
    onChange,
    label,
}: MultiSelectProps) {
    const [filteredOptions, setFilteredOptions] = useState(options);
    const [search, setSearch] = useState('');
    const searchInputRef = useRef<HTMLInputElement>(null);
    function handleSearch(term: string) {
        setSearch(term);
        setFilteredOptions(
            options.filter((o) =>
                o.label.toLowerCase().includes(term.toLowerCase()),
            ),
        );
    }
    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setFilteredOptions(options);
    }, [options]);

    return (
        <Select
            onValueChange={(e) => {
                onChange(e);
            }}
        >
            <SelectTrigger
                style={{
                    border: `1.5px solid`,
                    background: '#F8FAFC',
                    color: value ? '#1E293B' : '#94A3B8',
                    fontFamily: "'DM Sans',sans-serif",
                    cursor: 'pointer',
                }}
                onFocus={(e) => {
                    return (e.target.style.borderColor = '#009688');
                }}
                onKeyUp={(e) => e.preventDefault()}
                className="w-full appearance-none rounded-xl px-4 py-3 text-sm transition-all outline-none"
            >
                <SelectValue placeholder={`Select a ${label}`} />
            </SelectTrigger>

            <SelectContent className="z-9999">
                <SelectGroup
                    onKeyDown={() => searchInputRef.current?.focus()}
                    className="space-y-1"
                >
                    <input
                        value={search}
                        ref={searchInputRef}
                        onChange={(e) => handleSearch(e.target.value)}
                        placeholder="Search..."
                        className="w-full rounded-xl px-4 py-2 text-sm outline-none"
                    />

                    {filteredOptions.map((item) => (
                        <SelectItem
                            key={item.value}
                            value={item.value.toString()}
                            className={`cursor-pointer`}
                        >
                            {item.icon && (
                                <img
                                    src={item.icon}
                                    alt={item.label}
                                    className="mr-2 h-4 w-4"
                                />
                            )}
                            {item.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}
