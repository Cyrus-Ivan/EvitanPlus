import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useCurrency } from '@/hooks/use-currency';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, CartLine, DiscountType, Paginated, PaymentType, PosProduct } from '@/types';
import { Head, router } from '@inertiajs/react';
import { LoaderCircle, Minus, Package, Plus, Search, ShoppingCart, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Point of Sale', href: '/pos' }];

interface Filters {
    search: string | null;
}

const PAYMENT_LABELS: Record<PaymentType, string> = {
    cash: 'Cash',
    cashless: 'Cashless',
    others: 'Others',
};

export default function Pos({ products, filters }: { products: Paginated<PosProduct>; filters: Filters }) {
    const { symbol: currencySymbol, format: money } = useCurrency();
    const [search, setSearch] = useState(filters.search ?? '');
    const [cart, setCart] = useState<CartLine[]>([]);
    const [discountType, setDiscountType] = useState<DiscountType>('amount');
    const [discountInput, setDiscountInput] = useState('');
    const [paymentType, setPaymentType] = useState<PaymentType>('cash');
    const [cashReceived, setCashReceived] = useState('');
    const [comment, setComment] = useState('');
    const [processing, setProcessing] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);

    const addToCart = (product: PosProduct) => {
        if (product.current_stock <= 0) {
            return;
        }
        setCart((prev) => {
            const existing = prev.find((line) => line.variant_id === product.id);
            if (existing) {
                // Never let the client cart exceed on-hand; the ledger is the real
                // guard, this is just a UX cap.
                return prev.map((line) =>
                    line.variant_id === product.id ? { ...line, quantity: Math.min(line.quantity + 1, line.max_stock) } : line,
                );
            }
            return [
                ...prev,
                {
                    variant_id: product.id,
                    label: product.label,
                    unit_price: Number(product.selling_price),
                    quantity: 1,
                    max_stock: product.current_stock,
                },
            ];
        });
    };

    const setQuantity = (variantId: string, quantity: number) => {
        setCart((prev) =>
            prev.map((line) => (line.variant_id === variantId ? { ...line, quantity: Math.max(1, Math.min(quantity, line.max_stock)) } : line)),
        );
    };

    const removeLine = (variantId: string) => setCart((prev) => prev.filter((line) => line.variant_id !== variantId));

    // Search box doubles as a barcode-scan input: on Enter/click we first try an
    // exact barcode/SKU lookup and drop a match straight into the cart; anything
    // else falls through to a grid search. No per-keystroke requests.
    const runSearch = async () => {
        const code = search.trim();

        if (code) {
            try {
                const response = await fetch(route('pos.lookup', { code }), { headers: { Accept: 'application/json' } });
                const { variant } = (await response.json()) as { variant: PosProduct | null };
                if (variant && variant.current_stock > 0) {
                    addToCart(variant);
                    setSearch('');
                    return;
                }
            } catch {
                // Ignore and fall back to a normal grid search.
            }
        }

        router.get(
            route('pos'),
            { search: code || undefined },
            { preserveState: true, preserveScroll: true, replace: true, only: ['products', 'filters'] },
        );
    };

    const subtotal = useMemo(() => cart.reduce((sum, line) => sum + line.unit_price * line.quantity, 0), [cart]);
    const discountNum = Number(discountInput) || 0;
    const discountValue = discountType === 'percentage' ? (subtotal * Math.min(discountNum, 100)) / 100 : Math.min(discountNum, subtotal);
    const total = Math.max(subtotal - discountValue, 0);
    const cashNum = Number(cashReceived) || 0;
    const change = cashNum - total;
    const insufficientCash = paymentType === 'cash' && cashNum < total;

    const checkout = () => {
        if (cart.length === 0 || processing || insufficientCash) {
            return;
        }

        setProcessing(true);
        router.post(
            route('pos.checkout'),
            {
                lines: cart.map((line) => ({ variant_id: line.variant_id, quantity: line.quantity })),
                payment_type: paymentType,
                discount: discountNum > 0 ? discountNum : 0,
                discount_type: discountNum > 0 ? discountType : null,
                cash_received: paymentType === 'cash' ? cashNum : null,
                comment: comment.trim() || null,
            },
            {
                preserveScroll: true,
                // Inertia updates props without remounting the page, so the cart
                // state is not auto-reset — clear it here on success.
                onSuccess: () => {
                    setCart([]);
                    setDiscountInput('');
                    setCashReceived('');
                    setComment('');
                },
                // Show the first error regardless of its key — per-line failures
                // are keyed lines.0.variant_id etc., not a flat `lines`.
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Checkout failed.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    // Stable handle so the global-hotkey effect below never calls a stale closure.
    const checkoutRef = useRef(checkout);
    checkoutRef.current = checkout;

    // Page-level keyboard support. Any printable key typed outside a field sends
    // focus to the search box mid-keydown (the keystroke lands there), so a
    // barcode scan is never lost after clicking a tile or the cart. F9 fires
    // checkout. Space/Enter are left alone so focused product tiles stay
    // keyboard-activatable. Trade-off: a Code-39 barcode containing a literal
    // space would drop that character — fine for retail EAN/UPC/Code-128.
    useEffect(() => {
        const handler = (event: KeyboardEvent) => {
            if (document.querySelector('[role="dialog"][data-state="open"]')) {
                return;
            }

            if (event.key === 'F9') {
                event.preventDefault();
                checkoutRef.current();
                return;
            }

            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }
            if (event.key.length !== 1 || event.key === ' ') {
                return;
            }
            const target = event.target as HTMLElement | null;
            if (target?.closest('input, textarea, select, [contenteditable="true"]')) {
                return;
            }
            searchRef.current?.focus();
        };

        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Point of Sale" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4 lg:flex-row lg:items-start">
                {/* Left: product search + grid */}
                <div className="flex flex-1 flex-col gap-4">
                    <div className="flex items-center gap-2">
                        <div className="relative flex-1">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                            <Input
                                ref={searchRef}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        void runSearch();
                                    }
                                }}
                                placeholder="Search or scan — name, SKU, barcode"
                                className="pl-8"
                                autoFocus
                            />
                        </div>
                        <Button variant="outline" onClick={() => void runSearch()}>
                            Search
                        </Button>
                    </div>

                    {products.data.length === 0 ? (
                        <EmptyState
                            icon={Package}
                            title="No products found"
                            description={filters.search ? 'Try a different search term.' : 'Add items to the catalog to start selling.'}
                        />
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                            {products.data.map((product) => {
                                const outOfStock = product.current_stock <= 0;
                                return (
                                    <button
                                        key={product.id}
                                        type="button"
                                        disabled={outOfStock}
                                        onClick={() => addToCart(product)}
                                        className={cn(
                                            'flex min-h-28 flex-col rounded-lg border p-3 text-left transition',
                                            outOfStock
                                                ? 'cursor-not-allowed opacity-50'
                                                : 'hover:border-primary hover:bg-accent focus-visible:border-primary focus-visible:ring-ring focus-visible:ring-1 focus-visible:outline-none',
                                        )}
                                    >
                                        <span className="line-clamp-2 font-medium">{product.label}</span>
                                        <span className="text-muted-foreground mt-0.5 text-xs tabular-nums">{product.sku ?? '—'}</span>
                                        <div className="mt-auto flex items-center justify-between gap-2 pt-2">
                                            <span className="font-semibold tabular-nums">{money(product.selling_price)}</span>
                                            <Badge variant={outOfStock ? 'destructive' : 'secondary'} className="font-normal">
                                                {outOfStock ? 'Out' : `${product.current_stock} ${product.unit}`}
                                            </Badge>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    <Pagination links={products.links} />
                </div>

                {/* Right: cart + totals + payment */}
                <Card className="w-full shrink-0 p-4 lg:sticky lg:top-4 lg:w-96">
                    <div className="flex items-center gap-2 font-semibold">
                        <ShoppingCart className="size-4" /> Cart
                        {cart.length > 0 && <Badge variant="secondary">{cart.length}</Badge>}
                    </div>

                    <Separator className="my-3" />

                    {cart.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">Cart is empty. Search or scan a product to begin.</p>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {cart.map((line) => (
                                <div key={line.variant_id} className="flex items-start gap-2">
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{line.label}</p>
                                        <p className="text-muted-foreground text-xs tabular-nums">
                                            {money(line.unit_price)} × {line.quantity} = {money(line.unit_price * line.quantity)}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="size-7"
                                            aria-label="Decrease quantity"
                                            onClick={() => setQuantity(line.variant_id, line.quantity - 1)}
                                            disabled={line.quantity <= 1}
                                        >
                                            <Minus className="size-3" />
                                        </Button>
                                        <Input
                                            value={line.quantity}
                                            onChange={(e) => setQuantity(line.variant_id, Number(e.target.value) || 1)}
                                            inputMode="numeric"
                                            className="h-7 w-12 px-1 text-center tabular-nums"
                                            aria-label="Quantity"
                                        />
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="size-7"
                                            aria-label="Increase quantity"
                                            onClick={() => setQuantity(line.variant_id, line.quantity + 1)}
                                            disabled={line.quantity >= line.max_stock}
                                        >
                                            <Plus className="size-3" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="text-muted-foreground size-7"
                                            aria-label="Remove item"
                                            onClick={() => removeLine(line.variant_id)}
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <Separator className="my-3" />

                    {/* Discount */}
                    <div className="flex items-center gap-2">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={discountType}
                            onValueChange={(value) => value && setDiscountType(value as DiscountType)}
                        >
                            <ToggleGroupItem value="amount" className="px-3" aria-label="Amount discount">
                                {currencySymbol}
                            </ToggleGroupItem>
                            <ToggleGroupItem value="percentage" className="px-3" aria-label="Percentage discount">
                                %
                            </ToggleGroupItem>
                        </ToggleGroup>
                        <Input
                            value={discountInput}
                            onChange={(e) => setDiscountInput(e.target.value)}
                            inputMode="decimal"
                            placeholder="Discount"
                            aria-label="Discount value"
                            className="flex-1"
                        />
                    </div>

                    <dl className="mt-3 space-y-1 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">Subtotal</dt>
                            <dd className="tabular-nums">{money(subtotal)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">Discount</dt>
                            <dd className="tabular-nums">−{money(discountValue)}</dd>
                        </div>
                        <div className="flex justify-between text-base font-semibold">
                            <dt>Total</dt>
                            <dd className="tabular-nums">{money(total)}</dd>
                        </div>
                    </dl>

                    <Separator className="my-3" />

                    {/* Payment */}
                    <div className="space-y-2">
                        <Label>Payment</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={paymentType}
                            onValueChange={(value) => value && setPaymentType(value as PaymentType)}
                            className="justify-start"
                        >
                            {(Object.keys(PAYMENT_LABELS) as PaymentType[]).map((type) => (
                                <ToggleGroupItem key={type} value={type} className="flex-1">
                                    {PAYMENT_LABELS[type]}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>

                    {paymentType === 'cash' && (
                        <div className="mt-3 space-y-2">
                            <Label htmlFor="cash-received">Cash received</Label>
                            <Input
                                id="cash-received"
                                value={cashReceived}
                                onChange={(e) => setCashReceived(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        checkout();
                                    }
                                }}
                                inputMode="decimal"
                                placeholder="0.00"
                                className="tabular-nums"
                            />
                            <div className="flex justify-between text-sm">
                                <span className="text-muted-foreground">Change</span>
                                <span className={cn('tabular-nums', insufficientCash && 'text-destructive')}>{money(Math.max(change, 0))}</span>
                            </div>
                        </div>
                    )}

                    <div className="mt-3 space-y-2">
                        <Label htmlFor="sale-comment">Comment (optional)</Label>
                        <Textarea
                            id="sale-comment"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            rows={2}
                            maxLength={500}
                            placeholder="Add a note to this sale"
                            className="resize-none"
                        />
                    </div>

                    <Button
                        className="mt-4 w-full"
                        size="lg"
                        onClick={checkout}
                        disabled={cart.length === 0 || processing || insufficientCash}
                        title="Checkout (F9)"
                    >
                        {processing ? (
                            <>
                                <LoaderCircle className="h-4 w-4 animate-spin" /> Processing…
                            </>
                        ) : (
                            <>
                                Checkout · {money(total)}
                                <kbd className="ml-1 rounded border px-1 font-sans text-[10px] opacity-70">F9</kbd>
                            </>
                        )}
                    </Button>
                </Card>
            </div>
        </AppLayout>
    );
}
