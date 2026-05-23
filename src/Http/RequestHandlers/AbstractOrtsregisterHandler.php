<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\OrtsregisterModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gemeinsame Basis für alle Ortsregister-Request-Handler.
 *
 * Stellt $tree aus dem Request bereit, prüft Member-Berechtigung
 * und delegiert die eigentliche Logik an respond() der Subklasse.
 */
abstract class AbstractOrtsregisterHandler implements RequestHandlerInterface
{
    use ViewResponseTrait;

    // ---------------------------------------------------------------
    // PSR-15 Entry Point
    // ---------------------------------------------------------------

    final public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Tree|null $tree */
        try { $tree = Validator::attributes($request)->tree(); } catch (\Throwable $e) { $tree = null; }

        // Nicht eingeloggt oder kein Mitglied → zur Login-Seite umleiten
        if ($tree === null || !Auth::isMember($tree)) {
            $params = ['url' => (string) $request->getUri()];
            if ($tree !== null) {
                $params['tree'] = $tree->name();
            } else {
                // Tree-Name aus URL-Attributen lesen (auch ohne Auth-Prüfung)
                try {
                    $params['tree'] = Validator::attributes($request)->string('tree', '');
                } catch (\Throwable) {}
            }
            return redirect(route(\Fisharebest\Webtrees\Http\RequestHandlers\LoginPage::class, $params));
        }

        return $this->respond($request, $tree);
    }

    // ---------------------------------------------------------------
    // Template-Methode – muss von jeder Ortsregister-Seite implementiert werden
    // ---------------------------------------------------------------

    abstract protected function respond(
        ServerRequestInterface $request,
        ?Tree $tree
    ): ResponseInterface;

    // ---------------------------------------------------------------
    // Hilfsmethoden
    // ---------------------------------------------------------------

    /**
     * Gibt den voll qualifizierten View-Namen (Namespace::template) zurück.
     *
     * Beispiel: viewName('orte')  →  '_ortsregister_::orte'
     */
    protected function viewName(string $template): string
    {
        return OrtsregisterModule::MODULE_NAME . '::' . $template;
    }
}
