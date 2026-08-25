<?php

declare(strict_types=1);

namespace App\Curator;

use Awcodes\Curator\Concerns\UrlProvider;
use Awcodes\Curator\Glide\GlideBuilder;

/**
 * Curator's stock provider emits root-relative `/curator/...` URLs. This app is
 * mounted below `/sites/lead-generation-quiz`, so generate URLs through Laravel
 * to retain the configured public mount prefix while preserving Glide signatures.
 */
class MountedGlideUrlProvider implements UrlProvider
{
    public static function getThumbnailUrl(string $path): string
    {
        return static::absolute(GlideBuilder::make()->width(200)->height(200)->format('webp')->fit('crop')->toUrl($path));
    }

    public static function getMediumUrl(string $path): string
    {
        return static::absolute(GlideBuilder::make()->width(640)->height(640)->format('webp')->fit('crop')->toUrl($path));
    }

    public static function getLargeUrl(string $path): string
    {
        return static::absolute(GlideBuilder::make()->width(1024)->height(1024)->format('webp')->fit('contain')->toUrl($path));
    }

    private static function absolute(string $relativeUrl): string
    {
        return url(ltrim($relativeUrl, '/'));
    }
}
