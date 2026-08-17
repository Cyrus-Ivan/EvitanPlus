<?php

namespace App\Support\Sync;

/**
 * Is this computer attached to a network at all? (docs/glossary.md §5 "link check")
 *
 * The layer beneath {@see ConnectivityProbe}. The probe answers "can Supabase be
 * reached", which costs an HTTPS round trip and a two-second budget when the
 * answer is no. This answers the much smaller question the kernel already knows
 * the answer to, by reading two files, in about two milliseconds.
 *
 * ## Why a cheaper layer is worth having
 *
 * A socket does NOT fail fast just because the machine is obviously offline.
 * Measured on a development machine, forcing traffic onto an interface whose
 * `carrier` reads 0 still burned the probe's full two seconds before giving up —
 * the same as a router that is associated but black-holing packets. So "the OS
 * knows we are offline" buys nothing unless something actually asks the OS, and
 * nothing did: every screen paid the full timeout for a question that was free.
 *
 * ## "I cannot tell" is not "offline"
 *
 * This returns false ONLY when it positively establishes that no usable default
 * route exists. Everything else — a platform with no `/proc`, an unreadable file,
 * a route table in a shape not recognised here — is true, and the probe above
 * decides for real.
 *
 * That asymmetry is deliberate and is the same rule {@see ConnectivityProbe::reachable()}
 * follows for an underivable endpoint. A false negative here is far more
 * expensive than a false positive: guessing "offline" would leave a perfectly
 * connected branch unable to sync at all, reported as a network failure that
 * never happened, while guessing "online" costs one probe that was going to run
 * anyway.
 */
class NetworkLink
{
    /**
     * The all-zero destination that marks a default route, in the hex form both
     * kernel route tables print: eight nibbles for IPv4, thirty-two for IPv6.
     */
    private const DEFAULT_DESTINATION_V4 = '00000000';

    private const DEFAULT_DESTINATION_V6 = '00000000000000000000000000000000';

    /**
     * Loopback is never evidence of a network.
     *
     * It matters more than it looks: `/proc/net/ipv6_route` carries the kernel's
     * own unreachable/reject default routes on `lo`, so a check that did not
     * exclude it would find a "default route" on every Linux machine ever booted
     * and always answer true.
     */
    private const LOOPBACK = 'lo';

    public function __construct(
        private readonly string $routeTable = '/proc/net/route',
        private readonly string $routeTable6 = '/proc/net/ipv6_route',
        private readonly string $interfaces = '/sys/class/net',
    ) {}

    /**
     * Is there a default route out of this machine, on an interface that is up?
     */
    public function isUp(): bool
    {
        $v4 = $this->readLines($this->routeTable);
        $v6 = $this->readLines($this->routeTable6);

        // Neither table could be read: not Linux, or not permitted to look.
        if ($v4 === null && $v6 === null) {
            return true;
        }

        $candidates = array_merge(
            $this->defaultRoutesV4($v4 ?? []),
            $this->defaultRoutesV6($v6 ?? []),
        );

        if ($candidates === []) {
            return false;
        }

        foreach ($candidates as $interface) {
            if ($this->carrierIsUp($interface)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The interfaces carrying an IPv4 default route.
     *
     * The first row is a header. A default route is destination 0.0.0.0 — and
     * that is the WHOLE test: the gateway is deliberately not examined, because a
     * point-to-point link (WireGuard, OpenVPN) installs a default route with no
     * gateway at all, and requiring one would report a VPN-only device as
     * offline.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function defaultRoutesV4(array $lines): array
    {
        $interfaces = [];

        foreach (array_slice($lines, 1) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (($fields[1] ?? null) !== self::DEFAULT_DESTINATION_V4) {
                continue;
            }

            $interface = $fields[0] ?? '';

            if ($interface !== '' && $interface !== self::LOOPBACK) {
                $interfaces[] = $interface;
            }
        }

        return array_values(array_unique($interfaces));
    }

    /**
     * The interfaces carrying an IPv6 default route.
     *
     * No header row here, and the interface is the LAST field rather than the
     * first. A default is the all-zero destination at prefix length 0; anything
     * on `lo` is one of the kernel's own reject routes and is not a network.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function defaultRoutesV6(array $lines): array
    {
        $interfaces = [];

        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (($fields[0] ?? null) !== self::DEFAULT_DESTINATION_V6 || ($fields[1] ?? null) !== '00') {
                continue;
            }

            $interface = end($fields);

            if (is_string($interface) && $interface !== '' && $interface !== self::LOOPBACK) {
                $interfaces[] = $interface;
            }
        }

        return array_values(array_unique($interfaces));
    }

    /**
     * Does this interface have a carrier?
     *
     * Unreadable counts as up, for the same reason the whole class leans that
     * way: virtual interfaces do not all expose the file, and a `tun` device
     * carrying the only default route must not be read as a dead link. Only an
     * explicit `0` — the kernel saying the cable is out or the radio is off — is
     * treated as down.
     */
    private function carrierIsUp(string $interface): bool
    {
        // The interface name comes from a kernel-owned file, but it lands in a
        // path, so a traversal is refused rather than reasoned about.
        if (str_contains($interface, '/') || str_contains($interface, '\\')) {
            return true;
        }

        $carrier = $this->read($this->interfaces.'/'.$interface.'/carrier');

        if ($carrier === null) {
            return true;
        }

        return trim($carrier) !== '0';
    }

    /**
     * A file's lines, or null when it cannot be read at all.
     *
     * @return ?list<string>
     */
    private function readLines(string $path): ?array
    {
        $contents = $this->read($path);

        if ($contents === null) {
            return null;
        }

        $lines = preg_split('/\R/', trim($contents)) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * A file's contents, or null when it is not there or not readable.
     *
     * Guarded with `is_readable()` rather than silenced with `@`, which PHPUnit's
     * error handler sees straight through — an absent path is an EXPECTED input
     * to this class, so it must not surface as a warning.
     */
    private function read(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
