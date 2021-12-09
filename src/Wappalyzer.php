<?php
// Copyright 1999-2021. Plesk International GmbH. All rights reserved.

namespace Wappalyzer;

use Symfony\Component\DomCrawler\Crawler;

class Wappalyzer
{
    /** @var array */
    private $patterns = [];

    /** @var array */
    private $technologies = [
        'Laravel', 'CodeIgniter', 'Symfony', 'Yii',
        'Express', 'Angular', 'Next.js', 'Vue.js', 'React',
        'Ruby on Rails',
        'Django', 'Flask',
        'Microsoft ASP.NET',
        'WordPress', 'WooCommerce', 'Drupal', 'Magento', 'Prestashop', 'TYPO3 CMS', 'OpenCart', 'Moodle', 'Nextcloud',
    ];

    public function __construct(array $technologies = [])
    {
        $files = scandir(join(DIRECTORY_SEPARATOR, [__DIR__ , 'technologies']));
        foreach ($files as $file) {
            if (!in_array($file, ['..', '.'])) {
                $this->patterns = array_merge(
                    $this->patterns,
                    json_decode(file_get_contents(join(DIRECTORY_SEPARATOR, [__DIR__ , 'technologies', $file])), true)
                );
            }
        }

        if ($technologies) {
            $this->technologies = $technologies;
        }
    }

    public function analyze(string $url, string $host): array
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Host: {$host}", 'Is-Sitepreview: 1']);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_VERBOSE, false);
        curl_setopt($curl, CURLOPT_URL, $url);

        $content = curl_exec($curl);

        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $body = substr($content, $headerSize);

        $headers = $this->getHeaders(substr($content, 0, $headerSize));
        $cookies = $this->getCookies($content);
        $scripts = $this->getScripts($body);
        $metaTags = $this->getMetaTags($body);

        $resolved = [];
        foreach ($this->patterns as $name => $technology) {
            $technologyName = $this->getTechnologyName($name);
            if (!$technologyName || isset($resolved[$technologyName])) {
                continue;
            }
            if ($this->isResolved($technology, $url, $body, $scripts, $metaTags, $headers, $cookies)) {
                $resolved[$technologyName] = true;
            }
        }

        return array_keys($resolved);
    }

    private function getTechnologyName(string $name): string
    {
        if (in_array($name, $this->technologies)) {
            return $name;
        }

        $implies = $this->asArray($this->patterns[$name]['implies'] ?? []);
        foreach ($implies as $implied) {
            $impliedName = $this->parsePatterns($implied, false)[0];
            if (in_array($impliedName, $this->technologies)) {
                return $impliedName;
            }
        }
        return false;
    }

    private function getScripts(string $response): array
    {
        preg_match_all("/<script[^>]+>/i", $response, $matches);
        return $matches[0];
    }

    private function getCookies(string $response): array
    {
        $cookies = [];
        preg_match_all("/^Set-Cookie:\s*([^;]*)/mi", $response, $matches);

        foreach ($matches[1] as $match) {
            parse_str($match, $result);
            foreach ($result as $key => $value) {
                $cookies[strtolower($key)] = $value;
            }
        }
        return $cookies;
    }

    private function getHeaders(string $response): array
    {
        $headers = [];
        foreach (explode("\r\n", $response) as $line) {
            $parts = explode(': ', $line);
            if (count($parts) > 1) {
                $headers[strtolower($parts[0])] = $parts[1];
            }
        }
        return $headers;
    }

    private function getMetaTags(string $response): array
    {
        $tags = [];
        preg_match_all("/<meta[^>]+>/i", $response, $matches);

        foreach ($matches[0] as $match) {
            preg_match("/(?:name|property)=[\"']([^\"']+)[\"']/i", $match, $matchName);
            preg_match("/content=[\"']([^\"']+)[\"']/i", $match, $matchContent);

            if ($matchName && $matchContent) {
                $tags[strtolower($matchName[1])] = $matchContent[1];
            }
        }
        return $tags;
    }

    private function asArray($value): array
    {
        return is_array($value) ? $value : [$value];
    }

    private function parsePatterns($patterns, bool $escape = true): array
    {
        if (!$patterns) {
            return [];
        }
        $parsed = [];
        foreach ($this->asArray($patterns) as $key => $pattern) {
            $value = explode('\\;', $pattern)[0];
            $parsed[$key] = $escape ? str_replace('/', '\/', $value) : $value;
        }
        return $parsed;
    }

    private function isResolved(array $technology, string $url, string $body, array $scripts, array $metaTags, array $headers, array $cookies): bool
    {
        return $this->isResolvedUrl($technology, $url)
            || $this->isResolvedXhr($technology, $body)
            || $this->isResolvedHtml($technology, $body)
            || $this->isResolvedScripts($technology, $scripts)
            || $this->isResolvedMeta($technology, $metaTags)
            || $this->isResolvedHeaders($technology, $headers)
            || $this->isResolvedCookies($technology, $cookies)
            || $this->isResolvedDom($technology, $body);
    }

    private function isResolvedUrl(array $technology, string $url): bool
    {
        $patterns = $this->parsePatterns($technology['url'] ?? '');
        foreach ($patterns as $pattern) {
            if (preg_match("/{$pattern}/i", $url)) {
                return true;
            }
        }
        return false;
    }

    private function isResolvedXhr(array $technology, string $body): bool
    {
        $patterns = $this->parsePatterns($technology['xhr'] ?? '', false);
        foreach ($patterns as $pattern) {
            if (preg_match("/{$pattern}/i", $body)) {
                return true;
            }
        }
        return false;
    }

    private function isResolvedHtml(array $technology, string $body): bool
    {
        $patterns = $this->parsePatterns($technology['html'] ?? '', false);
        foreach ($patterns as $pattern) {
            if (preg_match("/{$pattern}/i", $body)) {
                return true;
            }
        }
        return false;
    }

    private function isResolvedScripts(array $technology, array $scripts): bool
    {
        $patterns = $this->parsePatterns($technology['scriptSrc'] ?? '');
        foreach ($patterns as $pattern) {
            foreach ($scripts as $script) {
                if (preg_match("/src=[\"'].*{$pattern}.*[\"']/i", $script)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isResolvedMeta(array $technology, array $metaTags): bool
    {
        $patterns = $this->parsePatterns($technology['meta'] ?? '');
        foreach ($patterns as $tagName => $pattern) {
            $tagName = strtolower($tagName);
            if (isset($metaTags[$tagName]) && preg_match("/{$pattern}/i", $metaTags[$tagName])) {
                return true;
            }
        }
        return false;
    }

    private function isResolvedHeaders(array $technology, array $headers): bool
    {
        $patterns = $this->parsePatterns($technology['headers'] ?? '');
        foreach ($patterns as $headerName => $pattern) {
            $headerName = strtolower($headerName);
            if (isset($headers[$headerName]) && preg_match("/{$pattern}/i", $headers[$headerName])) {
                return true;
            }
        }
        return false;
    }

    private function isResolvedCookies(array $technology, array $cookies): bool
    {
        $patterns = $this->parsePatterns($technology['cookies'] ?? '');
        foreach ($patterns as $cookieName => $pattern) {
            $cookieName = strtolower($cookieName);
            if (isset($cookies[$cookieName]) && preg_match("/{$pattern}/i", $cookies[$cookieName])) {
               return true;
            }
        }
        return false;
    }

    private function isResolvedDom(array $technology, string $body): bool
    {
        $patterns = $technology['dom'] ?? [];
        $crawler = new Crawler($body);
        foreach (is_array($patterns) ? array_keys($patterns) : [$patterns] as $pattern) {
            foreach ($pattern as $selector) {
                try {
                    $results = $crawler->filter($selector);
                    if ($results->count() > 0) {
                        return true;
                    }
                } catch (\Exception $e) { }
            }
        }
        return false;
    }
}
