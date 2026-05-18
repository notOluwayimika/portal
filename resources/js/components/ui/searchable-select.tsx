import React, { forwardRef } from 'react';
import Select, { Props as SelectProps, GroupBase } from 'react-select';
import { cn } from '@/lib/utils';

export interface SearchableSelectProps<
  Option = { label: string; value: string },
  IsMulti extends boolean = false,
  Group extends GroupBase<Option> = GroupBase<Option>
> extends SelectProps<Option, IsMulti, Group> {
  error?: boolean;
}

export const SearchableSelect = forwardRef<any, SearchableSelectProps<any, any, any>>(
  ({ className, error, ...props }, ref) => {
    return (
      <Select
        ref={ref}
        unstyled
        className={cn("w-full", className)}
        classNames={{
          control: ({ isFocused }) =>
            cn(
              "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
              isFocused ? "ring-1 ring-ring border-primary" : "border-input",
              error ? "border-destructive" : ""
            ),
          placeholder: () => "text-muted-foreground",
          input: () => "text-foreground",
          valueContainer: () => "gap-1",
          singleValue: () => "text-foreground",
          multiValue: () => "bg-muted rounded-sm text-sm flex items-center px-1 gap-1",
          multiValueLabel: () => "text-muted-foreground",
          multiValueRemove: () => "text-muted-foreground hover:bg-destructive hover:text-destructive-foreground rounded-sm",
          menu: () => "mt-2 rounded-md border border-border bg-popover text-popover-foreground shadow-md animate-in fade-in-0 zoom-in-95",
          menuList: () => "p-1",
          option: ({ isFocused, isSelected }) =>
            cn(
              "relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 px-2 text-sm outline-none transition-colors",
              isFocused ? "bg-accent text-accent-foreground" : "",
              isSelected ? "bg-primary text-primary-foreground font-medium" : ""
            ),
          noOptionsMessage: () => "py-6 text-center text-sm text-muted-foreground",
          loadingIndicator: () => "text-muted-foreground",
          clearIndicator: () => "text-muted-foreground hover:text-foreground",
          dropdownIndicator: () => "text-muted-foreground hover:text-foreground",
          indicatorSeparator: () => "bg-border mx-1",
        }}
        menuPlacement="auto"
        menuPosition="fixed"
        maxMenuHeight={250}
        minMenuHeight={150}
        menuShouldScrollIntoView={false}
        menuPortalTarget={typeof document !== 'undefined' ? document.body : null}
        styles={{
          menuPortal: (base) => ({ ...base, zIndex: 9999 }),
        }}
        {...props}
      />
    );
  }
);

SearchableSelect.displayName = 'SearchableSelect';
