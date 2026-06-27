<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Webtrees;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /tree/{tree}/orte/datei?pfad=orte/Haberschlacht/foo.jpg
 *
 * Liefert eine Datei aus dem Ortsbilder-Folder direkt aus —
 * keine OBJE-Record-Anforderung. Übernommen von Sammlungens
 * MediaDateiServe (Path-Traversal-Schutz + ACL-Check).
 */
final class PlaceFileServe implements RequestHandlerInterface
{
    private const MIME_TYPES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'mp3'  => 'audio/mpeg',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'txt'  => 'text/plain',
        'md'   => 'text/markdown',
    ];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isMember($tree, $user)) {
            return $this->responseFactory->createResponse(StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $pfad = Validator::queryParams($request)->string('pfad', '');
        $pfad = str_replace(['\\', '//'], ['/', '/'], $pfad);
        $pfad = (string) preg_replace('/\.\.+/', '', $pfad);
        $pfad = ltrim($pfad, '/');

        $mediaBase = Webtrees::DATA_DIR . $tree->getPreference('MEDIA_DIRECTORY', 'media/');
        $fullPath  = $mediaBase . $pfad;

        $realBase = realpath($mediaBase);
        $realFile = realpath($fullPath);

        if (
            $realBase === false
            || $realFile === false
            || !str_starts_with($realFile, $realBase)
            || !is_file($realFile)
        ) {
            return $this->responseFactory->createResponse(StatusCodeInterface::STATUS_NOT_FOUND);
        }

        $ext  = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $stream = $this->streamFactory->createStreamFromFile($realFile, 'rb');

        return $this->responseFactory
            ->createResponse(StatusCodeInterface::STATUS_OK)
            ->withHeader('Content-Type',        $mime)
            ->withHeader('Content-Length',      (string) filesize($realFile))
            ->withHeader('Content-Disposition', 'inline; filename="' . basename($realFile) . '"')
            ->withHeader('Cache-Control',       'private, max-age=3600')
            ->withBody($stream);
    }
}
