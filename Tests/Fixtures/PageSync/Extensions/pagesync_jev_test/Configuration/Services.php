<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\FakeJevClient;
use Webconsulting\WebconJev\Client\JevClientInterface;

/*
 * Puts the scripted client in place of webcon_jev's HTTP client. A compiler pass rather than an
 * alias in Services.yaml: extensions load alphabetically here, so webcon_jev's own alias would
 * come after ours and win.
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $container): void {
    // Public, so a test can script the answers and read what was asked.
    $container->register(FakeJevClient::class, FakeJevClient::class)->setPublic(true);
    $container->addCompilerPass(new class implements CompilerPassInterface {
        #[\Override]
        public function process(ContainerBuilder $container): void
        {
            $container->setAlias(JevClientInterface::class, FakeJevClient::class);
        }
    });
};
