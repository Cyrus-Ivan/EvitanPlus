<?php

namespace Tests\Unit\Sync;

use App\Support\Sync\NetworkLink;
use PHPUnit\Framework\TestCase;

/**
 * The link check (docs/glossary.md §5).
 *
 * Extends PHPUnit's TestCase rather than the app's: this class touches no
 * database, no container and no network, and the app's base binds a stub of the
 * very thing under test.
 *
 * What is being pinned is a bias, not a parser. The check may only ever answer
 * false when it is CERTAIN there is no way out of the machine, because a false
 * "offline" leaves a working branch unable to sync at all — reported as a network
 * failure that never happened — while a false "online" costs one probe that was
 * going to run regardless. Nearly every case below is a way that certainty could
 * be claimed by mistake.
 */
class NetworkLinkTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/network-link-'.uniqid();

        mkdir($this->root.'/sys', recursive: true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $carriers  Interface name => carrier contents.
     */
    private function link(?string $v4 = null, ?string $v6 = null, array $carriers = []): NetworkLink
    {
        $v4Path = $this->root.'/route';
        $v6Path = $this->root.'/ipv6_route';

        if ($v4 !== null) {
            file_put_contents($v4Path, $v4);
        }

        if ($v6 !== null) {
            file_put_contents($v6Path, $v6);
        }

        foreach ($carriers as $interface => $carrier) {
            mkdir($this->root.'/sys/'.$interface, recursive: true);
            file_put_contents($this->root.'/sys/'.$interface.'/carrier', $carrier);
        }

        return new NetworkLink($v4Path, $v6Path, $this->root.'/sys');
    }

    /** The header row the kernel prints, which must not be read as a route. */
    private const HEADER = "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n";

    public function test_a_default_route_on_a_live_interface_is_up(): void
    {
        $link = $this->link(
            v4: self::HEADER."wlp3s0\t00000000\t0164A8C0\t0003\t0\t0\t600\t00000000\t0\t0\t0\n",
            carriers: ['wlp3s0' => "1\n"],
        );

        $this->assertTrue($link->isUp());
    }

    public function test_no_default_route_is_down(): void
    {
        // The WiFi-off case: the subnet route survives, the default does not.
        $link = $this->link(
            v4: self::HEADER."wlp3s0\t0064A8C0\t00000000\t0001\t0\t0\t600\t00FFFFFF\t0\t0\t0\n",
            v6: '',
        );

        $this->assertFalse($link->isUp());
    }

    public function test_an_empty_route_table_is_down(): void
    {
        $this->assertFalse($this->link(v4: self::HEADER, v6: '')->isUp());
    }

    public function test_a_default_route_with_no_carrier_is_down(): void
    {
        // The measurement this whole class exists for: a route can outlive the
        // link that served it, and the socket still burns the full probe budget
        // discovering that.
        $link = $this->link(
            v4: self::HEADER."enp2s0f1\t00000000\t0164A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n",
            v6: '',
            carriers: ['enp2s0f1' => "0\n"],
        );

        $this->assertFalse($link->isUp());
    }

    public function test_one_live_interface_among_several_is_up(): void
    {
        $link = $this->link(
            v4: self::HEADER
                ."enp2s0f1\t00000000\t0164A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n"
                ."wlp3s0\t00000000\t0164A8C0\t0003\t0\t0\t600\t00000000\t0\t0\t0\n",
            v6: '',
            carriers: ['enp2s0f1' => "0\n", 'wlp3s0' => "1\n"],
        );

        $this->assertTrue($link->isUp());
    }

    public function test_a_gatewayless_default_route_is_up(): void
    {
        // A point-to-point link — WireGuard, OpenVPN — installs a default route
        // with no gateway. Requiring one would report a VPN-only till as offline,
        // which is the exact failure this class must never produce.
        $link = $this->link(
            v4: self::HEADER."wg0\t00000000\t00000000\t0001\t0\t0\t0\t00000000\t0\t0\t0\n",
            v6: '',
        );

        $this->assertTrue($link->isUp(), 'A tunnel carrying the default route is a network.');
    }

    public function test_an_interface_with_no_carrier_file_is_up(): void
    {
        // Same reasoning, one layer down: not every virtual interface exposes
        // `carrier`, and absence is not a zero.
        $link = $this->link(
            v4: self::HEADER."tun0\t00000000\t00000000\t0001\t0\t0\t0\t00000000\t0\t0\t0\n",
            v6: '',
        );

        $this->assertTrue($link->isUp());
    }

    public function test_an_ipv6_default_route_counts_when_there_is_no_ipv4_one(): void
    {
        $zeros = str_repeat('0', 32);

        $link = $this->link(
            v4: self::HEADER,
            v6: "{$zeros} 00 {$zeros} 00 {$zeros} 00000400 00000001 00000000 00000003   wlp3s0\n",
            carriers: ['wlp3s0' => "1\n"],
        );

        $this->assertTrue($link->isUp());
    }

    public function test_the_kernels_own_loopback_defaults_are_not_a_network(): void
    {
        // `/proc/net/ipv6_route` carries all-zero unreachable/reject defaults on
        // `lo` on every booted Linux machine. Counting them would make this class
        // answer true unconditionally and silently stop doing anything at all.
        $zeros = str_repeat('0', 32);

        $link = $this->link(
            v4: self::HEADER,
            v6: "{$zeros} 00 {$zeros} 00 {$zeros} ffffffff 00000001 00000000 00200200       lo\n"
                ."{$zeros} 00 {$zeros} 00 {$zeros} ffffffff 00000001 00000000 00200200       lo\n",
        );

        $this->assertFalse($link->isUp());
    }

    public function test_unreadable_route_tables_are_treated_as_up(): void
    {
        // Not Linux, or not permitted to look. "I cannot tell" must fall through
        // to the probe rather than become an answer.
        $link = new NetworkLink(
            $this->root.'/absent',
            $this->root.'/absent6',
            $this->root.'/sys',
        );

        $this->assertTrue($link->isUp());
    }
}
