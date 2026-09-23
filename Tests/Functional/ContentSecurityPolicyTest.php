<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Route as SymfonyRoute;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Disposition;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyProvider;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The backend policy as the CSP middleware builds it: core's backend
 * mutations plus every PolicyMutatedEvent listener, for a request on a
 * given route.
 */
final class ContentSecurityPolicyTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = ['webconsulting/docx-editor'];

    #[Test]
    public function theEditorRouteAllowsWebAssemblyAndBlobImages(): void
    {
        $policy = $this->policyFor('docx_editor');

        self::assertTrue($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::wasmUnsafeEval));
        self::assertTrue($policy->containsDirective(Directive::ImgSrc, SourceScheme::blob));
        self::assertFalse($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::unsafeEval));
        // The core backend policy stays underneath.
        self::assertTrue($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::nonceProxy));
        self::assertTrue($policy->containsDirective(Directive::ObjectSrc, SourceKeyword::none));
    }

    #[Test]
    public function thePageEditorRouteAllowsTheSameEngine(): void
    {
        $policy = $this->policyFor('docx_editor_page');

        self::assertTrue($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::wasmUnsafeEval));
        self::assertTrue($policy->containsDirective(Directive::ImgSrc, SourceScheme::blob));
        self::assertFalse($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::unsafeEval));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherRoutes(): array
    {
        return [
            'backend main frame' => ['main'],
            'file list' => ['media_management'],
            'editor AJAX route' => ['ajax_docx_editor_document_load'],
            'import as subpages' => ['docx_editor_page_new'],
            'page download' => ['docx_editor_page_download'],
            'page preview AJAX route' => ['ajax_docx_editor_page_preview'],
        ];
    }

    #[Test]
    #[DataProvider('otherRoutes')]
    public function otherBackendRoutesKeepTheCorePolicy(string $routeIdentifier): void
    {
        $policy = $this->policyFor($routeIdentifier);

        self::assertFalse($policy->isEmpty());
        self::assertFalse($policy->containsDirective(Directive::ScriptSrc, SourceKeyword::wasmUnsafeEval));
        self::assertFalse($policy->containsDirective(Directive::ImgSrc, SourceScheme::blob));
    }

    private function policyFor(string $routeIdentifier): Policy
    {
        $route = $this->get(Router::class)->getRoute($routeIdentifier);
        self::assertInstanceOf(SymfonyRoute::class, $route);
        $backendRoute = Route::fromSymfonyRoute($route, $routeIdentifier);
        $request = (new ServerRequest('https://example.com/typo3' . $backendRoute->getPath()))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $backendRoute);

        return $this->get(PolicyProvider::class)->provideFor(Scope::backend(), Disposition::enforce, $request);
    }
}
