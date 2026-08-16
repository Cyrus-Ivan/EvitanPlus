import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ALL } from '@/lib/filters';
import type { Category } from '@/types';

/**
 * Narrow a list to one category — Items and Inventory, which had it written out
 * identically.
 *
 * The list is the categories that exist, not the ones with anything in them: a
 * category you just made and have not filled yet is exactly the one you would
 * filter by to check.
 */
export default function CategoryFilter({
    value,
    categories,
    onChange,
    label = 'Category',
}: {
    value: string | null;
    categories: Pick<Category, 'id' | 'name'>[];
    onChange: (value: string) => void;
    label?: string;
}) {
    return (
        <Select value={value ?? ALL} onValueChange={onChange}>
            <SelectTrigger className="w-44" aria-label={label}>
                <SelectValue placeholder="All categories" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>All categories</SelectItem>
                {categories.map((category) => (
                    <SelectItem key={category.id} value={category.id}>
                        {category.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
