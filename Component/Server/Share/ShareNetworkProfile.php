<?php

namespace Pinoox\Component\Server\Share;

final class ShareNetworkProfile
{
    private bool $cloudflareFiltered;

    private bool $fakeIpDns;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $this->fakeIpDns = $this->detectFakeIpDns();
        $this->cloudflareFiltered = $this->fakeIpDns || !ShareToolkit::canReachHttps('https://api.trycloudflare.com/tunnel', 2);
    }

    public function cloudflareFiltered(): bool
    {
        return $this->cloudflareFiltered;
    }

    public function fakeIpDns(): bool
    {
        return $this->fakeIpDns;
    }

    public function prefersSshTunnels(): bool
    {
        return $this->fakeIpDns || $this->cloudflareFiltered;
    }

    public function summary(): ?string
    {
        if ($this->fakeIpDns) {
            return 'Proxy/VPN DNS detected — SSH-based tunnels are preferred over Cloudflare here.';
        }

        if ($this->cloudflareFiltered) {
            return 'Cloudflare API unreachable from this network — trying other providers first.';
        }

        return null;
    }

    private function detectFakeIpDns(): bool
    {
        $records = @dns_get_record('api.trycloudflare.com', DNS_A);

        if (!is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? '');

            if ($ip === '') {
                continue;
            }

            if (str_starts_with($ip, '198.18.') || str_starts_with($ip, '10.10.34.')) {
                return true;
            }
        }

        return false;
    }
}
