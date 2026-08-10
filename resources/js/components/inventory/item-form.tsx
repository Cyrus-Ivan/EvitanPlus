import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import type { Category, Item } from '@/types';
import { Transition } from '@headlessui/react';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

// Declared as `type` (not `interface`) so they satisfy Inertia's
// Record<string, FormDataConvertible> constraint on useForm.
type VariantRow = {
    id?: string;
    name: string;
    sku: string;
    barcode: string;
    selling_price: string;
    low_stock_threshold: string;
    cost: string;
};

type ItemFormData = {
    name: string;
    unit: string;
    category_id: string;
    description: string;
    variants: VariantRow[];
};

const NO_CATEGORY = 'none';

function blankVariant(): VariantRow {
    return { name: '', sku: '', barcode: '', selling_price: '', low_stock_threshold: '0', cost: '' };
}

function toRow(variant: NonNullable<Item['variants']>[number]): VariantRow {
    return {
        id: variant.id,
        name: variant.name ?? '',
        sku: variant.sku ?? '',
        barcode: variant.barcode ?? '',
        selling_price: variant.selling_price ?? '',
        low_stock_threshold: String(variant.low_stock_threshold ?? 0),
        cost: variant.cost ?? '',
    };
}

/**
 * Shared create/edit form for an Item and its Variants. Removing a saved variant
 * simply drops it from the submitted array; the server archives it (never
 * deletes). The Item Cost column renders only for the Owner.
 */
export default function ItemForm({ item, categories }: { item?: Item; categories: Pick<Category, 'id' | 'name'>[] }) {
    const { can } = usePermissions();
    const canViewCost = can('view-item-cost');
    const isEdit = Boolean(item);

    const form = useForm<ItemFormData>({
        name: item?.name ?? '',
        unit: item?.unit ?? '',
        category_id: item?.category_id ?? NO_CATEGORY,
        description: item?.description ?? '',
        variants: item?.variants?.length ? item.variants.map(toRow) : [blankVariant()],
    });

    const { data, setData, errors, processing, recentlySuccessful } = form;

    // "This item has multiple variants" toggle. Off = a single "no variant"
    // product: one variant with a null name that just references the item.
    // Default off for new items; on when editing anything richer than a lone
    // unnamed variant.
    const [hasVariants, setHasVariants] = useState<boolean>(() => {
        const variants = item?.variants;
        if (!variants?.length) {
            return false;
        }
        return variants.length > 1 || Boolean(variants[0].name);
    });

    // Switching to single-product mode collapses to one unnamed variant. The
    // extra rows drop out of the payload, so the server archives them (never
    // deletes — recoverable via the Archived filter).
    const setSingleProduct = (single: boolean) => {
        setHasVariants(!single);
        if (single) {
            setData('variants', [{ ...data.variants[0], name: '' }]);
        }
    };

    // Nested error keys (e.g. "variants.0.sku") fall outside the typed top-level
    // keys, so read them through a loosely-typed view of the same object.
    const err = errors as Record<string, string | undefined>;

    const setVariant = (index: number, field: keyof VariantRow, value: string) => {
        setData(
            'variants',
            data.variants.map((row, i) => (i === index ? { ...row, [field]: value } : row)),
        );
    };

    // Adding a variant also switches to multi-variant mode, which is how a
    // "no variant" item gets converted: the lone unnamed variant gains a Name
    // field and the new row appears alongside it.
    const addVariant = () => {
        setHasVariants(true);
        setData('variants', [...data.variants, blankVariant()]);
    };

    const removeVariant = (index: number) =>
        setData(
            'variants',
            data.variants.filter((_, i) => i !== index),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        form.transform((payload) => ({
            ...payload,
            category_id: payload.category_id === NO_CATEGORY ? '' : payload.category_id,
            // Single-product mode submits exactly one variant; the rest are
            // dropped so the server archives them.
            variants: (hasVariants ? (payload.variants as VariantRow[]) : (payload.variants as VariantRow[]).slice(0, 1)).map((row) => {
                const variant: Record<string, unknown> = {
                    // Blank name persists as null (a "no variant" product).
                    name: row.name.trim() === '' ? null : row.name,
                    sku: row.sku,
                    barcode: row.barcode,
                    selling_price: row.selling_price,
                    low_stock_threshold: row.low_stock_threshold,
                };
                if (row.id) {
                    variant.id = row.id;
                }
                // Only the Owner may send cost; the server strips it regardless.
                if (canViewCost) {
                    variant.cost = row.cost;
                }
                return variant;
            }),
        }));

        if (isEdit && item) {
            form.patch(route('items.update', item.id), { preserveScroll: true });
        } else {
            form.post(route('items.store'), { preserveScroll: true });
        }
    };

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-8">
            <section className="space-y-4">
                <HeadingSmall title="Item details" description="Products your store sells. Stock, price, and SKU live on the variants below." />

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            placeholder="e.g. Coca-Cola 1.5L"
                            readOnly={isEdit}
                            className={isEdit ? 'bg-muted text-muted-foreground' : undefined}
                        />
                        {isEdit && <p className="text-muted-foreground text-xs">Item names can’t be changed after creation.</p>}
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit">Unit</Label>
                        <Input
                            id="unit"
                            value={data.unit}
                            onChange={(e) => setData('unit', e.target.value)}
                            required
                            placeholder="e.g. pc, kg, box"
                        />
                        <InputError message={errors.unit} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="category_id">Category</Label>
                        <Select value={data.category_id} onValueChange={(value) => setData('category_id', value)}>
                            <SelectTrigger id="category_id">
                                <SelectValue placeholder="No category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_CATEGORY}>No category</SelectItem>
                                {categories.map((category) => (
                                    <SelectItem key={category.id} value={category.id}>
                                        {category.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.category_id} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="description">Description</Label>
                        <Textarea
                            id="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Optional notes about this item"
                        />
                        <InputError message={errors.description} />
                    </div>
                </div>
            </section>

            <section className="space-y-4">
                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title="Variants"
                        description={
                            hasVariants
                                ? 'Every item needs at least one variant.'
                                : 'This is a single product. Stock, price, SKU and barcode live below.'
                        }
                    />
                    {/* Shown on edit too, so a single-product item can gain
                        variants after the fact (additive only — collapsing back
                        to single-product stays a create-time choice). */}
                    {(hasVariants || isEdit) && (
                        <Button type="button" variant="outline" size="sm" onClick={addVariant}>
                            <Plus className="size-4" /> Add variant
                        </Button>
                    )}
                </div>

                {/* The single-product vs. multi-variant choice is fixed at creation
                    time, so the toggle only appears on the create form. */}
                {!isEdit && (
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={hasVariants} onCheckedChange={(checked) => setSingleProduct(checked !== true)} />
                        This item has multiple variants
                    </label>
                )}

                {/* The `variants` array error (e.g. "at least one required") surfaces here. */}
                <InputError message={errors.variants} />

                <div className="space-y-4">
                    {(hasVariants ? data.variants : data.variants.slice(0, 1)).map((variant, index) => (
                        <div key={variant.id ?? index} className="rounded-lg border p-4">
                            {hasVariants && (
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="text-muted-foreground text-sm font-medium">Variant {index + 1}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeVariant(index)}
                                        disabled={data.variants.length === 1}
                                        title={data.variants.length === 1 ? 'An item must keep at least one variant' : 'Remove variant'}
                                        aria-label={data.variants.length === 1 ? 'An item must keep at least one variant' : 'Remove variant'}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            )}

                            <div className={`grid gap-3 ${canViewCost ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
                                {hasVariants && (
                                    <div className="grid gap-2">
                                        <Label htmlFor={`variant-${index}-name`}>Name</Label>
                                        <Input
                                            id={`variant-${index}-name`}
                                            value={variant.name}
                                            onChange={(e) => setVariant(index, 'name', e.target.value)}
                                            placeholder="e.g. Small, Large"
                                            required
                                        />
                                        <InputError message={err[`variants.${index}.name`]} />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor={`variant-${index}-sku`}>SKU</Label>
                                    {/* SKUs are stored uppercase; normalize as the user types
                                        (the server re-normalizes regardless). */}
                                    <Input
                                        id={`variant-${index}-sku`}
                                        value={variant.sku}
                                        onChange={(e) => setVariant(index, 'sku', e.target.value.toUpperCase())}
                                        placeholder="e.g. WATER-500"
                                        required
                                    />
                                    <InputError message={err[`variants.${index}.sku`]} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={`variant-${index}-barcode`}>Barcode</Label>
                                    <Input
                                        id={`variant-${index}-barcode`}
                                        value={variant.barcode}
                                        onChange={(e) => setVariant(index, 'barcode', e.target.value)}
                                        placeholder="Optional"
                                    />
                                    <InputError message={err[`variants.${index}.barcode`]} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={`variant-${index}-price`}>Selling price</Label>
                                    <Input
                                        id={`variant-${index}-price`}
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={variant.selling_price}
                                        onChange={(e) => setVariant(index, 'selling_price', e.target.value)}
                                        required
                                    />
                                    <InputError message={err[`variants.${index}.selling_price`]} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={`variant-${index}-threshold`}>Low-stock threshold</Label>
                                    <Input
                                        id={`variant-${index}-threshold`}
                                        type="number"
                                        step="1"
                                        min="0"
                                        value={variant.low_stock_threshold}
                                        onChange={(e) => setVariant(index, 'low_stock_threshold', e.target.value)}
                                    />
                                    <InputError message={err[`variants.${index}.low_stock_threshold`]} />
                                </div>

                                {canViewCost && (
                                    <div className="grid gap-2">
                                        <Label htmlFor={`variant-${index}-cost`}>Cost</Label>
                                        <Input
                                            id={`variant-${index}-cost`}
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={variant.cost}
                                            onChange={(e) => setVariant(index, 'cost', e.target.value)}
                                            placeholder="Owner only"
                                        />
                                        <InputError message={err[`variants.${index}.cost`]} />
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            <div className="flex items-center gap-4">
                <Button disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEdit ? 'Save changes' : 'Create item'}
                </Button>
                <Button type="button" variant="outline" asChild>
                    <Link href={route('items.index')}>Cancel</Link>
                </Button>
                <Transition
                    show={recentlySuccessful}
                    enter="transition ease-in-out"
                    enterFrom="opacity-0"
                    leave="transition ease-in-out"
                    leaveTo="opacity-0"
                >
                    <p className="text-sm text-neutral-600">Saved</p>
                </Transition>
            </div>
        </form>
    );
}
