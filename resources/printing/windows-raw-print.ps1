# Writes a file's raw bytes to a Windows print queue in RAW (passthrough) mode,
# so an ESC/POS byte stream reaches a thermal printer untouched by the driver.
# Invoked by App\Support\Printing\Connectors\SystemPrinterConnector on Windows.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File windows-raw-print.ps1 `
#       -PrinterName "<queue name>" -FilePath "<path to raw bytes>"
#
# Exits 0 on success; on any failure it writes a message to stderr and exits 1.

param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][string]$FilePath
)

$ErrorActionPreference = 'Stop'

# P/Invoke the winspool RAW API: OpenPrinter -> StartDocPrinter(RAW) ->
# StartPagePrinter -> WritePrinter -> EndPagePrinter -> EndDocPrinter ->
# ClosePrinter. This is the only reliable way to send raw bytes to a queue
# without a printer driver interpreting them.
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public static class RawPrinterHelper
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct DOCINFO
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDatatype;
    }

    [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern bool OpenPrinter(string src, out IntPtr hPrinter, IntPtr pd);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern int StartDocPrinter(IntPtr hPrinter, int level, ref DOCINFO di);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr hPrinter, byte[] buf, int count, out int written);

    public static void Send(string printerName, byte[] bytes)
    {
        IntPtr hPrinter;
        if (!OpenPrinter(printerName, out hPrinter, IntPtr.Zero))
            throw new Exception("OpenPrinter failed (" + Marshal.GetLastWin32Error() + ")");
        try
        {
            DOCINFO di = new DOCINFO();
            di.pDocName = "Evitan Plus receipt";
            di.pDatatype = "RAW";
            if (StartDocPrinter(hPrinter, 1, ref di) == 0)
                throw new Exception("StartDocPrinter failed (" + Marshal.GetLastWin32Error() + ")");
            try
            {
                if (!StartPagePrinter(hPrinter))
                    throw new Exception("StartPagePrinter failed (" + Marshal.GetLastWin32Error() + ")");
                int written;
                if (!WritePrinter(hPrinter, bytes, bytes.Length, out written) || written != bytes.Length)
                    throw new Exception("WritePrinter failed (" + Marshal.GetLastWin32Error() + ")");
                EndPagePrinter(hPrinter);
            }
            finally { EndDocPrinter(hPrinter); }
        }
        finally { ClosePrinter(hPrinter); }
    }
}
"@

try {
    if (-not (Test-Path -LiteralPath $FilePath)) {
        [Console]::Error.WriteLine("Receipt file not found: $FilePath")
        exit 1
    }

    $bytes = [System.IO.File]::ReadAllBytes($FilePath)
    [RawPrinterHelper]::Send($PrinterName, $bytes)
    exit 0
}
catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
