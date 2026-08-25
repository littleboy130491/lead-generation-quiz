<?php

namespace App\Services;

use Illuminate\Http\Request;

class SubmissionContext
{
    /** @return array<string, mixed> */
    public function capture(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        $userAgent = $this->limit((string) $request->userAgent(), 1024);

        return [
            'landing_url' => $this->urlWithoutQuery($request->url()),
            'query' => $this->sanitizeQuery($request->query()),
            'referrer' => $this->sanitizeUrl((string) $request->headers->get('referer')),
            'ip' => $this->limit((string) $request->ip(), 45),
            'user_agent' => $userAgent,
            'client' => $this->parseUserAgent($userAgent),
        ];
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function sanitizeQuery(array $query): array
    {
        $result = [];
        foreach (array_slice($query, 0, 50, true) as $key => $value) {
            $key = strtolower((string) $key);
            if (! $this->isAllowedAttributionKey($key) || ! is_scalar($value)) {
                continue;
            }

            $result[$key] = $this->limit((string) $value, 512);
        }

        return $result;
    }

    private function isAllowedAttributionKey(string $key): bool
    {
        if (in_array($key, [
            'gclid', 'dclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id',
            'campaign', 'campaign_id', 'campaign_name', 'source', 'medium', 'channel',
            'adgroup', 'adgroup_id', 'ad_id', 'creative', 'creative_id', 'placement',
            'network', 'ref', 'affiliate', 'affiliate_id', 'partner', 'partner_id', 'click_id',
        ], true)) {
            return true;
        }

        return preg_match('/^utm_(source|medium|campaign|term|content|id|source_platform|creative_format|marketing_tactic)$/', $key) === 1;
    }

    private function sanitizeUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $this->limit($url, 2048);
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $sanitizedQuery = $this->sanitizeQuery($query);
        $base = ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '');

        return $this->limit($base.($sanitizedQuery === [] ? '' : '?'.http_build_query($sanitizedQuery)), 2048);
    }

    private function urlWithoutQuery(string $url): string
    {
        return $this->limit(strtok($url, '?') ?: $url, 2048);
    }

    /** @return array{browser:string,device:string,platform:string} */
    private function parseUserAgent(string $userAgent): array
    {
        $browser = preg_match('/Edg\//i', $userAgent) ? 'Edge' : (preg_match('/Firefox\//i', $userAgent) ? 'Firefox' : (preg_match('/Chrome\//i', $userAgent) ? 'Chrome' : (preg_match('/Safari\//i', $userAgent) ? 'Safari' : 'Other')));
        $device = preg_match('/iPad|Tablet/i', $userAgent) ? 'tablet' : (preg_match('/Mobile|Android/i', $userAgent) ? 'mobile' : 'desktop');
        $platform = preg_match('/Windows/i', $userAgent) ? 'Windows' : (preg_match('/Android/i', $userAgent) ? 'Android' : (preg_match('/iPhone|iPad|Mac OS/i', $userAgent) ? 'Apple' : (preg_match('/Linux/i', $userAgent) ? 'Linux' : 'Other')));

        return compact('browser', 'device', 'platform');
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }
}
