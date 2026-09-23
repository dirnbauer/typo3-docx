<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\EventListener;

use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;

/**
 * Widens the backend Content-Security-Policy on the editor route only:
 *
 *   - script-src 'wasm-unsafe-eval': the engine shapes text with HarfBuzz,
 *     compiled to WebAssembly (Resources/Public/Vite/assets/harfbuzz-*.wasm,
 *     same origin). It allows compiling WebAssembly, not JavaScript eval.
 *   - img-src blob: the engine paints the document's images from blob: URLs
 *     it creates from the package bytes.
 *
 * Every other backend route keeps the core policy. The engine runs on the
 * file editor and on the page round trip's "Edit in Word" screen.
 */
final readonly class AllowEditorEngineInContentSecurityPolicy
{
    public const string ROUTE = 'docx_editor';
    public const string PAGE_ROUTE = 'docx_editor_page';

    #[AsEventListener('docx-editor/content-security-policy')]
    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!$event->scope->type->isBackend() || $event->request === null) {
            return;
        }
        $route = $event->request->getAttribute('route');
        if (!$route instanceof Route || !in_array($route->getOption('_identifier'), [self::ROUTE, self::PAGE_ROUTE], true)) {
            return;
        }

        $event->setCurrentPolicy($event->getCurrentPolicy()->mutate(
            new Mutation(MutationMode::Extend, Directive::ScriptSrc, SourceKeyword::wasmUnsafeEval),
            new Mutation(MutationMode::Extend, Directive::ImgSrc, SourceScheme::blob),
        ));
    }
}
