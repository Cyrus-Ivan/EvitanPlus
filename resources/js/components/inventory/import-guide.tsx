import { Badge } from '@/components/ui/badge';

interface ColumnDoc {
    name: string;
    required: boolean;
    type: string;
    description: React.ReactNode;
}

const COLUMNS: ColumnDoc[] = [
    {
        name: 'category',
        required: false,
        type: 'text',
        description:
            'Groups the product. Created automatically if it doesn’t exist yet (archived categories must be restored first). On an existing product a different (active) category moves it — blank keeps the current one. Category is the one item-level field an update can change.',
    },
    {
        name: 'product',
        required: true,
        type: 'text',
        description:
            'The item name (e.g. Bottled Water). Rows sharing the same product name group under one item. For an existing SKU it must match that SKU’s product — names can’t be changed by import.',
    },
    {
        name: 'variant',
        required: false,
        type: 'text',
        description:
            'The specific version — size, flavor, color (e.g. 500ml). Blank on a new product means a single “no variant” product. Fixed after creation: on an existing SKU leave it blank (or repeat the current name) — a different value is rejected. If any of a product’s rows is named, every row for that product must be named.',
    },
    {
        name: 'sku',
        required: true,
        type: 'text',
        description:
            'Unique stock code and the match key. An existing SKU updates that variant; a new one creates it. Must be unique within the file. Letters are stored uppercase — water-500 and WATER-500 are the same SKU.',
    },
    {
        name: 'barcode',
        required: false,
        type: 'text',
        description:
            'Scannable code, set when the variant is created. Unique when provided — a blank barcode is fine and never blocks a sale. Fixed after creation: on an existing SKU leave it blank (or repeat the current value) — a different barcode is rejected.',
    },
    {
        name: 'selling_price',
        required: true,
        type: 'number',
        description: 'Price to customers. Up to 2 decimals, not negative, at most 9999999999.99.',
    },
    {
        name: 'cost',
        required: false,
        type: 'number',
        description:
            'Owner only — ignored on import for anyone else, and left off the template. Up to 2 decimals, not negative. Blank keeps the current cost.',
    },
    {
        name: 'unit',
        required: false,
        type: 'text',
        description:
            'Unit of measure — pc, kg, box. Set when the product is created (defaults to “pc”). Fixed after creation: on an existing product leave it blank (or repeat the current value) — a different unit is rejected.',
    },
    {
        name: 'description',
        required: false,
        type: 'text',
        description:
            'Free-text notes, set when the product is created. Fixed after creation: on an existing product leave it blank (or repeat the current value) — a different description is rejected.',
    },
    {
        name: 'low_stock_threshold',
        required: false,
        type: 'whole number',
        description:
            'The level at which a variant is flagged “Low” on the Inventory page. A property of the product, the same at every branch — not a stock figure. Defaults to 0 for new variants; blank keeps the current value.',
    },
];

const EXAMPLE_HEADERS = ['category', 'product', 'variant', 'sku', 'barcode', 'selling_price', 'cost', 'unit', 'description', 'low_stock_threshold'];
const EXAMPLE_ROW = ['Beverages', 'Bottled Water', '500ml', 'WATER-500', '4801234567890', '15.00', '9.50', 'pc', 'Purified drinking water', '12'];

const REJECTION_REASONS = [
    'The upload isn’t a CSV, is larger than 5 MB, is empty, has no data rows, or isn’t UTF-8 encoded (re-save as “CSV UTF-8”).',
    'A required column is missing from the header (product, sku, selling_price).',
    'A required cell is blank on a row (product, sku, or selling_price).',
    'A text cell is too long (product/variant/sku/barcode/category: 255, unit: 50, description: 1000 characters).',
    'selling_price or cost isn’t a number with up to 2 decimals, is negative, or exceeds 9999999999.99.',
    'low_stock_threshold isn’t a whole number (within ±999999999), or is negative.',
    'The same SKU appears on more than one row (uppercase/lowercase count as the same SKU).',
    'The same barcode appears on more than one row, or is already used by a different product (including archived ones).',
    'A row’s SKU or product name matches something you’ve archived — restore it under Items first.',
    'A row’s product cell doesn’t match the product its SKU belongs to — the import can’t rename products.',
    'An existing SKU’s row tries to change its barcode, variant name, unit, or description — those are fixed after creation. Leave the cell blank or repeat the current value.',
    'A row moves a product into an archived category — restore the category under Items first.',
    'Rows describing the same product disagree on its category, unit, or description — make them match, or leave all but one blank.',
    'The same brand-new product name is repeated with no variant name (duplicate rows) — name each row differently, or remove the duplicate.',
    'A product mixes a blank (no-variant) row with named ones — name every row for that product, or remove the blank.',
    'A row adds a named variant to a product that’s already a single no-variant item — name its existing variant under Items first.',
];

function Mono({ children }: { children: React.ReactNode }) {
    return <code className="bg-muted rounded px-1 py-0.5 font-mono text-[0.8em]">{children}</code>;
}

function Callout({ title, tone = 'accent', children }: { title: string; tone?: 'accent' | 'warn'; children: React.ReactNode }) {
    const toneClasses = tone === 'warn' ? 'border-destructive/30 bg-destructive/5' : 'border-primary/30 bg-primary/5';
    return (
        <div className={`rounded-lg border p-3 ${toneClasses}`}>
            <p className="text-xs font-semibold tracking-wide uppercase">{title}</p>
            <div className="text-muted-foreground mt-1 text-sm">{children}</div>
        </div>
    );
}

function SectionHeading({ children }: { children: React.ReactNode }) {
    return <h3 className="text-sm font-semibold">{children}</h3>;
}

/**
 * The column documentation, as a panel rather than a dialog of its own.
 *
 * It was a nested `Dialog` with its own trigger until M13, when the import
 * became a modal itself. Two stacked Radix dialogs is more focus-trap trouble
 * than the content is worth, and a `<details>` in the host modal reads the same.
 *
 * `stock` and `mode` are gone from the file the importer accepts, so they are
 * gone from here — a CSV describes products, and what is on a shelf is a claim
 * somebody at the branch has to make. The consequence is stated rather than
 * papered over: an imported product opens at zero.
 */
export default function ImportGuide() {
    return (
        <div className="space-y-6 text-sm">
            <section className="space-y-3">
                <SectionHeading>How the import thinks</SectionHeading>
                <Callout title="Matched by SKU">
                    The <Mono>sku</Mono> is the key. If a row’s SKU already exists, that product is <strong>updated</strong> — and you will be shown
                    exactly which ones before anything is saved. If the SKU is new, the category, product and variant are <strong>created</strong> for
                    you.
                </Callout>
                <Callout title="It imports products, never stock" tone="warn">
                    There is no <Mono>stock</Mono> column. An imported product opens at <strong>zero</strong>, and its first count comes from{' '}
                    <strong>Batch adjustment</strong> on the Inventory page — where somebody at the branch confirms what is actually on the shelf.
                </Callout>
                <Callout title="What an update can change" tone="warn">
                    For an <strong>existing SKU</strong>, an update only touches <Mono>selling_price</Mono>, <Mono>cost</Mono>,{' '}
                    <Mono>low_stock_threshold</Mono> and <Mono>category</Mono>. Everything else is fixed after creation — and a cell that tries to
                    change it is a <strong>row error</strong>, never silently ignored:
                    <ul className="mt-1.5 list-disc space-y-1 pl-5">
                        <li>
                            The <Mono>product</Mono> name, <Mono>variant</Mono> name, <Mono>barcode</Mono>, <Mono>unit</Mono> and{' '}
                            <Mono>description</Mono> can’t be changed by import. Leave the cell blank, or repeat the current value — edit these under{' '}
                            <strong>Items</strong>.
                        </li>
                        <li>
                            <Mono>category</Mono> is the exception: a different (active) category <strong>moves</strong> the product; blank keeps the
                            current one.
                        </li>
                        <li>
                            You <strong>can’t rename a SKU</strong> here. Change a SKU and it reads as a brand-new variant, leaving the old one
                            untouched.
                        </li>
                    </ul>
                </Callout>
                <Callout title="All or nothing" tone="warn">
                    The whole file is validated before anything is saved. If <strong>any</strong> row has a problem, the import is rejected and{' '}
                    <strong>nothing changes</strong> — the errors name the row and the reason so you can fix it and re-upload.
                </Callout>
                <Callout title="Single product vs. variants">
                    A product is either a <strong>single “no variant” product</strong> (one row, blank <Mono>variant</Mono>) or has{' '}
                    <strong>named variants</strong> (every row for that product carries a <Mono>variant</Mono> name). To turn an existing single
                    product into one with variants, open it under <strong>Items</strong>, name its existing variant, then re-import the new ones.
                </Callout>
                <Callout title="Archived items are skipped" tone="warn">
                    A row whose SKU or product name matches something you’ve <strong>archived</strong> is rejected — restore it under{' '}
                    <strong>Items</strong> first, then import.
                </Callout>
            </section>

            <section className="space-y-3">
                <SectionHeading>The columns</SectionHeading>
                <p className="text-muted-foreground">
                    Order doesn’t matter and headers are case-insensitive — <Mono>Selling Price</Mono> and <Mono>selling_price</Mono> both match.
                    Unknown columns are ignored.
                </p>
                <div className="divide-y rounded-lg border">
                    {COLUMNS.map((column) => (
                        <div key={column.name} className="grid gap-1 p-3 sm:grid-cols-[11rem_1fr] sm:gap-4">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                <span className="font-mono text-sm font-medium">{column.name}</span>
                                {column.required ? (
                                    <Badge className="text-[0.65rem]">Required</Badge>
                                ) : (
                                    <Badge variant="secondary" className="text-[0.65rem]">
                                        Optional
                                    </Badge>
                                )}
                                <span className="text-muted-foreground font-mono text-xs">{column.type}</span>
                            </div>
                            <p className="text-muted-foreground min-w-0">{column.description}</p>
                        </div>
                    ))}
                </div>
            </section>

            <section className="space-y-3">
                <SectionHeading>Example</SectionHeading>
                <p className="text-muted-foreground">The header row plus one product — exactly what the template contains.</p>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full border-collapse font-mono text-xs whitespace-nowrap">
                        <thead>
                            <tr className="bg-muted/60 text-muted-foreground">
                                {EXAMPLE_HEADERS.map((header) => (
                                    <th key={header} className="border-r px-2.5 py-1.5 text-left font-medium last:border-r-0">
                                        {header}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                {EXAMPLE_ROW.map((cell, index) => (
                                    <td key={index} className="border-r px-2.5 py-1.5 tabular-nums last:border-r-0">
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="space-y-3">
                <SectionHeading>Rules &amp; limits</SectionHeading>
                <ul className="text-muted-foreground list-disc space-y-1.5 pl-5">
                    <li>
                        The file must be a <Mono>.csv</Mono> up to 5 MB.
                    </li>
                    <li>Prices use up to 2 decimals and are never negative.</li>
                    <li>
                        SKU is required on every row and unique within the file — case doesn’t matter, letters are stored uppercase. Barcode is unique
                        across all products, including archived ones.
                    </li>
                    <li>
                        <Mono>variant</Mono> is optional: blank on a new product means a single “no variant” product, blank on an existing SKU keeps
                        its current name. An item can’t mix a blank variant with named ones — name every row for a product that has variants.
                    </li>
                    <li>Rows that match an archived product, variant, or category are rejected until you restore it under Items.</li>
                    <li>Cost is Owner-only; for others it’s simply ignored.</li>
                    <li>
                        Blank rows are skipped. Blank optional cells <strong>keep the existing value</strong> for matched products and use defaults
                        for new ones — an import never blanks out a barcode or description.
                    </li>
                </ul>
            </section>

            <section className="space-y-3">
                <SectionHeading>Why an import gets rejected</SectionHeading>
                <p className="text-muted-foreground">Any one of these stops the whole file — nothing is saved. Fix it and re-upload.</p>
                <ul className="space-y-1.5">
                    {REJECTION_REASONS.map((reason) => (
                        <li key={reason} className="flex gap-2">
                            <span aria-hidden className="bg-destructive mt-1.5 size-1.5 shrink-0 rotate-45" />
                            <span className="text-muted-foreground">{reason}</span>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}
