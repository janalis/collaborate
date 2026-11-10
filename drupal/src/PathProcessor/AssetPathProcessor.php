<?php

declare(strict_types=1);

namespace Drupal\collaborate\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A module of the bundle is addressed by its path (`/collaborate-assets/<version>/draft/session.js`), which Drupal's
 * routes cannot hold in a parameter: the path past the version moves to the `file` query parameter, as Drupal does
 * for its own files.
 */
final class AssetPathProcessor implements InboundPathProcessorInterface
{
    private const string PREFIX = '/collaborate-assets/';

    public function processInbound($path, Request $request): string
    {
        if (!str_starts_with($path, self::PREFIX)) {
            return $path;
        }
        $rest = substr($path, \strlen(self::PREFIX));
        $slash = strpos($rest, '/');
        if (false === $slash) {
            return $path;
        }
        $request->query->set('file', substr($rest, $slash + 1));

        return self::PREFIX . substr($rest, 0, $slash);
    }
}
