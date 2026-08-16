import InputError from '@/components/forms/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

// Booleans ride as '1'/'0' strings, matching the string-valued settings table
// and the Laravel `boolean` validation rule (which accepts '1'/'0').
export interface PrinterSettingsData {
    receipt_paper_size: string;
    receipt_connection: string;
    receipt_printer_name: string;
    receipt_device_path: string;
    receipt_network_host: string;
    receipt_network_port: string;
    auto_print: string;
    open_cash_drawer: string;
    [key: string]: string;
}

interface DetectedPrinter {
    name: string;
    label: string;
}

// The printer configuration fields, shared by the Business Settings printer
// form and the setup wizard so the two can never drift apart. Owns the
// OS-print-queue detection state; `detectUrl` points at the page-appropriate
// detection endpoint (auth'd vs pre-auth setup).
export default function PrinterSettingsFields({
    data,
    setData,
    errors,
    detectUrl,
}: {
    data: PrinterSettingsData;
    setData: (key: string, value: string) => void;
    errors: Partial<Record<string, string>>;
    detectUrl: string;
}) {
    const [detectedPrinters, setDetectedPrinters] = useState<DetectedPrinter[]>([]);
    const [detecting, setDetecting] = useState(false);
    const [detected, setDetected] = useState(false);

    const detectPrinters = useCallback(async () => {
        setDetecting(true);
        try {
            const response = await fetch(detectUrl, {
                headers: { Accept: 'application/json' },
            });
            const body = (await response.json()) as { printers?: DetectedPrinter[] };
            setDetectedPrinters(body.printers ?? []);
        } catch {
            setDetectedPrinters([]);
        } finally {
            setDetected(true);
            setDetecting(false);
        }
    }, [detectUrl]);

    const connection = data.receipt_connection;

    // Discover OS print queues the first time the "System printer" option is in
    // play (only returns names inside the packaged app; empty under artisan serve).
    useEffect(() => {
        if (connection === 'system' && !detected && !detecting) {
            void detectPrinters();
        }
    }, [connection, detected, detecting, detectPrinters]);

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="receipt_paper_size">Paper width</Label>
                <Select value={data.receipt_paper_size} onValueChange={(value) => setData('receipt_paper_size', value)}>
                    <SelectTrigger id="receipt_paper_size" className="w-56">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="58mm">58 mm</SelectItem>
                        <SelectItem value="80mm">80 mm</SelectItem>
                    </SelectContent>
                </Select>
                <InputError message={errors.receipt_paper_size} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="receipt_connection">Connection</Label>
                <Select value={data.receipt_connection} onValueChange={(value) => setData('receipt_connection', value)}>
                    <SelectTrigger id="receipt_connection" className="w-56">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="system">System printer (USB / Bluetooth via OS)</SelectItem>
                        <SelectItem value="usb">USB device file (Linux)</SelectItem>
                        <SelectItem value="bluetooth">Bluetooth serial (Linux / macOS)</SelectItem>
                        <SelectItem value="network">Network (Wi-Fi / Ethernet)</SelectItem>
                    </SelectContent>
                </Select>
                <p className="text-muted-foreground text-xs">
                    <strong>System printer</strong> is recommended on macOS &amp; Windows — install the printer in the OS, then pick it below.
                </p>
                <InputError message={errors.receipt_connection} />
            </div>

            {connection === 'system' ? (
                <div className="grid gap-2">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="receipt_printer_name">Printer</Label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1.5 text-xs"
                            onClick={() => void detectPrinters()}
                            disabled={detecting}
                        >
                            <RefreshCw className={detecting ? 'size-3.5 animate-spin' : 'size-3.5'} />
                            {detecting ? 'Detecting…' : 'Detect printers'}
                        </Button>
                    </div>
                    {detectedPrinters.length > 0 && (
                        <Select
                            value={detectedPrinters.some((p) => p.name === data.receipt_printer_name) ? data.receipt_printer_name : ''}
                            onValueChange={(value) => setData('receipt_printer_name', value)}
                        >
                            <SelectTrigger id="receipt_printer_name" className="w-full">
                                <SelectValue placeholder="Choose a detected printer" />
                            </SelectTrigger>
                            <SelectContent>
                                {detectedPrinters.map((p) => (
                                    <SelectItem key={p.name} value={p.name}>
                                        {p.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Input
                        value={data.receipt_printer_name}
                        onChange={(e) => setData('receipt_printer_name', e.target.value)}
                        placeholder="Printer queue name"
                        aria-label="Printer queue name"
                    />
                    <p className="text-muted-foreground text-xs">
                        {detected && detectedPrinters.length === 0
                            ? 'No printers detected automatically — type the exact OS print-queue name.'
                            : 'Pick a detected printer, or type the exact OS print-queue name.'}
                    </p>
                    <InputError message={errors.receipt_printer_name} />
                </div>
            ) : connection === 'network' ? (
                <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                    <div className="grid gap-2">
                        <Label htmlFor="receipt_network_host">Printer IP address</Label>
                        <Input
                            id="receipt_network_host"
                            value={data.receipt_network_host}
                            onChange={(e) => setData('receipt_network_host', e.target.value)}
                            placeholder="192.168.1.50"
                        />
                        <InputError message={errors.receipt_network_host} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="receipt_network_port">Port</Label>
                        <Input
                            id="receipt_network_port"
                            type="number"
                            className="w-28"
                            value={data.receipt_network_port}
                            onChange={(e) => setData('receipt_network_port', e.target.value)}
                            placeholder="9100"
                        />
                        <InputError message={errors.receipt_network_port} />
                    </div>
                </div>
            ) : (
                <div className="grid gap-2">
                    <Label htmlFor="receipt_device_path">Device path</Label>
                    <Input
                        id="receipt_device_path"
                        value={data.receipt_device_path}
                        onChange={(e) => setData('receipt_device_path', e.target.value)}
                        placeholder="/dev/usb/lp0"
                    />
                    <p className="text-muted-foreground text-xs">
                        Linux USB printers are usually <code>/dev/usb/lp0</code>; a paired Bluetooth printer is <code>/dev/rfcomm0</code> (Linux) or{' '}
                        <code>/dev/cu.*</code> (macOS). The app needs write access to the device. On macOS/Windows, prefer{' '}
                        <strong>System printer</strong> above.
                    </p>
                    <InputError message={errors.receipt_device_path} />
                </div>
            )}

            <label className="flex items-start gap-3">
                <Checkbox checked={data.auto_print === '1'} onCheckedChange={(value) => setData('auto_print', value === true ? '1' : '0')} />
                <span className="grid gap-1">
                    <span className="text-sm font-medium">Print automatically after each sale</span>
                    <span className="text-muted-foreground text-xs">A failed print never cancels the sale — you can always reprint from Sales.</span>
                </span>
            </label>

            <label className="flex items-start gap-3">
                <Checkbox
                    checked={data.open_cash_drawer === '1'}
                    onCheckedChange={(value) => setData('open_cash_drawer', value === true ? '1' : '0')}
                />
                <span className="grid gap-1">
                    <span className="text-sm font-medium">Open the cash drawer when printing</span>
                    <span className="text-muted-foreground text-xs">Sends a drawer-kick pulse to a drawer wired to the printer.</span>
                </span>
            </label>
        </>
    );
}
