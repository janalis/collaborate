<?php

declare(strict_types=1);

namespace Drupal\collaborate\Controller;

use Drupal\collaborate\Page\AssetUrls;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Serves a module of the bundle's `assets/` to the pages, cached for as long as its version holds. */
final class AssetController
{
    /** The module the path processor moved to the `file` query parameter. */
    public function __invoke(string $version, Request $request): BinaryFileResponse
    {
        $path = $request->query->getString('file');
        $root = realpath(AssetUrls::directory());
        $file = false === $root ? false : realpath($root . '/' . $path);
        if (
            false === $root
            || false === $file
            || !str_starts_with($file, $root . \DIRECTORY_SEPARATOR)
            || 1 !== preg_match('#^[a-z0-9/_.-]+\.js$#D', $path)
            || !str_ends_with($file, '.js')
            || !is_file($file)
        ) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        if ($version === AssetUrls::version()) {
            $response->setPublic();
            $response->setMaxAge(31_536_000);
            $response->setImmutable();
        }

        return $response;
    }
}
