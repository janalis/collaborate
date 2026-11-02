<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Cursor\PlaceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The place of a library page: its folder, whatever the address it was reached by and the view it is shown in.
 * A folder revoked ({@see revoke()}, a file of the application's log directory, which every worker reads) is a
 * place nobody may enter.
 */
final readonly class HostPlaces implements PlaceProviderInterface
{
    private const array ROUTES = ['library', 'library_home', 'library_listing'];

    public function __construct(
        private string $directory,
    ) {}

    public function place(Request $request): ?array
    {
        $folder = $request->attributes->get('folder');
        if (!\in_array($request->attributes->get('_route'), self::ROUTES, true) || !\is_string($folder)) {
            return null;
        }

        return ['library', $folder];
    }

    public function canEnter(UserInterface $user, array $place): bool
    {
        return !\in_array($place[1] ?? null, $this->revoked(), true);
    }

    public function revoke(string $folder, bool $revoked = true): void
    {
        $folders = array_values(array_diff($this->revoked(), [$folder]));
        if ($revoked) {
            $folders[] = $folder;
        }
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o777, true);
        }
        file_put_contents($this->file(), json_encode($folders, \JSON_THROW_ON_ERROR), \LOCK_EX);
    }

    /** @return list<string> */
    private function revoked(): array
    {
        $json = is_file($this->file()) ? (string) file_get_contents($this->file()) : '[]';
        $folders = json_decode($json, true);

        $revoked = [];
        foreach (\is_array($folders) ? $folders : [] as $folder) {
            if (\is_string($folder)) {
                $revoked[] = $folder;
            }
        }

        return $revoked;
    }

    private function file(): string
    {
        return $this->directory . '/revoked-folders.json';
    }
}
