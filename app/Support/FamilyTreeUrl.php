<?php

namespace App\Support;

use App\Models\FamilyTree;

class FamilyTreeUrl
{
    /**
     * Public family site URL. Preferred order:
     * custom verified domain -> family subdomain -> legacy /family/{slug}.
     */
    public function tree(FamilyTree $tree, array $query = []): string
    {
        return $this->withQuery($this->baseFor($tree), $query);
    }

    public function person(FamilyTree $tree, int|string $personId, array $query = []): string
    {
        $base = rtrim($this->baseFor($tree), '/');

        if ($this->usesDomainUrl($tree)) {
            return $this->withQuery($base.'/person/'.$personId, $query);
        }

        return $this->withQuery(route('family.tree.person', [
            'tree' => $tree,
            'person' => $personId,
        ]), $query);
    }

    public function isFamilySubdomain(string $host): bool
    {
        return $this->slugFromHost($host) !== null;
    }

    public function slugFromHost(string $host): ?string
    {
        if (! $this->subdomainsEnabled()) {
            return null;
        }

        $host = mb_strtolower(trim($host, '.'));
        $base = mb_strtolower($this->subdomainBaseDomain());
        if ($host === $base || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $slug = mb_substr($host, 0, -mb_strlen('.'.$base));
        if ($slug === '' || str_contains($slug, '.') || in_array($slug, $this->reservedSubdomains(), true)) {
            return null;
        }

        return preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $slug) ? $slug : null;
    }

    private function baseFor(FamilyTree $tree): string
    {
        if ($this->customDomainActive($tree)) {
            return $this->scheme().'://'.$tree->primary_domain;
        }

        if ($this->subdomainsEnabled() && $this->slugAllowed($tree->slug)) {
            return $this->scheme().'://'.$tree->slug.'.'.$this->subdomainBaseDomain();
        }

        return route('family.tree', $tree);
    }

    private function usesDomainUrl(FamilyTree $tree): bool
    {
        return $this->customDomainActive($tree)
            || ($this->subdomainsEnabled() && $this->slugAllowed($tree->slug));
    }

    private function customDomainActive(FamilyTree $tree): bool
    {
        return filled($tree->primary_domain) && $tree->domain_status === 'active';
    }

    private function subdomainsEnabled(): bool
    {
        return (bool) config('platform.family_subdomains.enabled', false);
    }

    private function subdomainBaseDomain(): string
    {
        return (string) config('platform.family_subdomains.domain', config('platform.domains.international'));
    }

    private function slugAllowed(?string $slug): bool
    {
        return filled($slug) && ! in_array(mb_strtolower((string) $slug), $this->reservedSubdomains(), true);
    }

    private function reservedSubdomains(): array
    {
        return config('platform.family_subdomains.reserved', []);
    }

    private function scheme(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
    }

    private function withQuery(string $url, array $query): string
    {
        $query = array_filter($query, fn ($value): bool => $value !== null && $value !== '');

        return $query ? $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query) : $url;
    }
}
