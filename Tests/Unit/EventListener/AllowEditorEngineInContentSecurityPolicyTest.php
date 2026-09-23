<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\EventListener\AllowEditorEngineInContentSecurityPolicy;

final class AllowEditorEngineInContentSecurityPolicyTest extends UnitTestCase
{
    #[Test]
    public function theEditorRouteMayCompileWebAssemblyAndShowBlobImages(): void
    {
        $policy = $this->mutate(Scope::backend(), 'docx_editor');

        self::assertTrue($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::wasmUnsafeEval));
        self::assertTrue($policy->containsDirective(Directive::ImgSrc, SourceScheme::blob));
        self::assertFalse($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::unsafeEval));
    }

    #[Test]
    public function otherBackendRoutesAreLeftAlone(): void
    {
        self::assertTrue($this->mutate(Scope::backend(), 'media_management')->isEmpty());
        self::assertTrue($this->mutate(Scope::backend(), 'ajax_docx_editor_document_load')->isEmpty());
    }

    #[Test]
    public function requestsWithoutARouteAreLeftAlone(): void
    {
        self::assertTrue($this->mutate(Scope::backend(), null)->isEmpty());
        self::assertTrue($this->mutate(Scope::frontend(), 'docx_editor')->isEmpty());
    }

    private function mutate(Scope $scope, ?string $routeIdentifier): Policy
    {
        $request = new ServerRequest('https://example.com/typo3/docx-editor/edit');
        if ($routeIdentifier !== null) {
            $request = $request->withAttribute('route', new Route('/docx-editor/edit', ['_identifier' => $routeIdentifier]));
        }
        $event = new PolicyMutatedEvent($scope, $request, new Policy(), new Policy());
        (new AllowEditorEngineInContentSecurityPolicy())($event);

        return $event->getCurrentPolicy();
    }
}
