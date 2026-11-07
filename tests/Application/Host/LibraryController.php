<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Notification\Notifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * The library: a folder's page, its listing alone (which the page loads in place of its own to go to another
 * folder), and, in the browser environments, `POST /e2e/folders/{folder}/revoke` (`{"revoked": bool}`).
 */
final readonly class LibraryController
{
    /** The folders and how many records each holds: enough in `music` to scroll its pane. */
    public const array FOLDERS = ['music' => 40, 'films' => 3];

    public function __construct(
        private Environment $twig,
        private HostPlaces $places,
        private Notifier $notifier,
    ) {}

    public function page(Request $request, string $folder): Response
    {
        return new Response($this->twig->render('library.html.twig', $this->listed($request, $folder)));
    }

    public function listing(Request $request, string $folder): Response
    {
        return new Response($this->twig->render('_listing.html.twig', $this->listed($request, $folder)));
    }

    /** What the library would tell somebody: a notification the scenarios send. */
    public function notify(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $given = \is_array($data['parameters'] ?? null) ? $data['parameters'] : [];
        $parameters = array_combine(array_map(strval(...), array_keys($given)), array_map(strval(...), $given));
        $this->notifier->notify(
            \is_int($data['to'] ?? null) ? $data['to'] : 0,
            \is_string($data['type'] ?? null) ? $data['type'] : 'record_shared',
            $parameters,
            \is_string($data['url'] ?? null) ? $data['url'] : null,
        );

        return new JsonResponse(['notified' => true]);
    }

    public function revoke(Request $request, string $folder): JsonResponse
    {
        $this->places->revoke($folder, true === ($request->toArray()['revoked'] ?? true));

        return new JsonResponse(['folder' => $folder]);
    }

    /** @return array{folder: string, view: string, records: list<array{key: string, name: string, size: int}>, folders: list<string>} */
    private function listed(Request $request, string $folder): array
    {
        if (!isset(self::FOLDERS[$folder])) {
            throw new NotFoundHttpException('No such folder.');
        }
        $records = [];
        for ($index = 1; $index <= self::FOLDERS[$folder]; $index++) {
            $records[] = ['key' => $folder . '-' . $index, 'name' => $folder . '-' . $index . '.mp3', 'size' => $index];
        }

        return [
            'folder' => $folder,
            'view' => 'tiles' === $request->query->get('view') ? 'tiles' : 'list',
            'records' => $records,
            'folders' => array_keys(self::FOLDERS),
        ];
    }
}
