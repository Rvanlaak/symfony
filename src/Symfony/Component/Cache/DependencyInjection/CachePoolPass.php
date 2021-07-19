<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache\DependencyInjection;

use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\ParameterNormalizer;
use Symfony\Component\Cache\Messenger\EarlyExpirationDispatcher;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class CachePoolPass implements CompilerPassInterface
{
    private const POOL_ATTRIBUTES = [
        'provider',
        'name',
        'namespace',
        'default_lifetime',
        'early_expiration_message_bus',
        'reset',
    ];
    private array $clearers = [];
    private array $allPools = [];

    /**
     * Remove the early expiration handler after iterating all pools when none of the pools needs it.
     */
    private bool $messageHandlerIsNeeded = false;

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        if ($container->hasParameter('cache.prefix.seed')) {
            $seed = $container->getParameterBag()->resolveValue($container->getParameter('cache.prefix.seed'));
        } else {
            $seed = '_'.$container->getParameter('kernel.project_dir');
            $seed .= '.'.$container->getParameter('kernel.container_class');
        }

        foreach ($container->findTaggedServiceIds('cache.pool') as $serviceId => $tags) {
            $poolDefinition = $container->getDefinition($serviceId);
            $this->processPool($poolDefinition, $container, $tags, $serviceId, $seed);
        }

        if (!$this->messageHandlerIsNeeded) {
            $container->removeDefinition('cache.early_expiration_handler');
        }

        $notAliasedCacheClearerId = $aliasedCacheClearerId = 'cache.global_clearer';
        while ($container->hasAlias('cache.global_clearer')) {
            $aliasedCacheClearerId = (string) $container->getAlias('cache.global_clearer');
        }
        if ($container->hasDefinition($aliasedCacheClearerId)) {
            $this->clearers[$notAliasedCacheClearerId] = $this->allPools;
        }

        foreach ($this->clearers as $id => $pools) {
            $clearer = $container->getDefinition($id);
            if ($clearer instanceof ChildDefinition) {
                $clearer->replaceArgument(0, $pools);
            } else {
                $clearer->setArgument(0, $pools);
            }
            $clearer->addTag('cache.pool.clearer');

            if ('cache.system_clearer' === $id) {
                $clearer->addTag('kernel.cache_clearer');
            }
        }

        if ($container->hasDefinition('console.command.cache_pool_list')) {
            $container->getDefinition('console.command.cache_pool_list')->replaceArgument(0, array_keys($this->allPools));
        }
    }

    private function getNamespace(string $seed, string $id)
    {
        return substr(str_replace('/', '-', base64_encode(hash('sha256', $id.$seed, true))), 0, 10);
    }

    /**
     * @internal
     */
    public static function getServiceProvider(ContainerBuilder $container, string $name)
    {
        $container->resolveEnvPlaceholders($name, null, $usedEnvs);

        if ($usedEnvs || preg_match('#^[a-z]++:#', $name)) {
            $dsn = $name;

            if (!$container->hasDefinition($name = '.cache_connection.'.ContainerBuilder::hash($dsn))) {
                $definition = new Definition(AbstractAdapter::class);
                $definition->setPublic(false);
                $definition->setFactory([AbstractAdapter::class, 'createConnection']);
                $definition->setArguments([$dsn, ['lazy' => true]]);
                $container->setDefinition($name, $definition);
            }
        }

        return $name;
    }

    private function processPool(Definition $poolDefinition, ContainerBuilder $container, array $tags, string $serviceId, string $seed): void
    {
        if ($poolDefinition->isAbstract()) {
            return;
        }

        $pool = $poolDefinition;

        $class = $poolDefinition->getClass();
        while ($poolDefinition instanceof ChildDefinition) {
            $poolDefinition = $container->findDefinition($poolDefinition->getParent());
            $class = $class ?: $poolDefinition->getClass();
            if ($t = $poolDefinition->getTag('cache.pool')) {
                $tags[0] += $t[0];
            }
        }
        $name = $tags[0]['name'] ?? $serviceId;
        if (!isset($tags[0]['namespace'])) {
            $namespaceSeed = $seed;
            if (null !== $class) {
                $namespaceSeed .= '.' . $class;
            }

            $tags[0]['namespace'] = $this->getNamespace($namespaceSeed, $name);
        }
        if (isset($tags[0]['clearer'])) {
            $clearer = $tags[0]['clearer'];
            while ($container->hasAlias($clearer)) {
                $clearer = (string)$container->getAlias($clearer);
            }
        } else {
            $clearer = null;
        }
        unset($tags[0]['clearer'], $tags[0]['name']);

        if (isset($tags[0]['provider'])) {
            $tags[0]['provider'] = new Reference(static::getServiceProvider($container, $tags[0]['provider']));
        }

        if (ChainAdapter::class === $class) {
            $chainablePools = [];
            foreach ($poolDefinition->getArgument(0) as $provider => $chainablePool) {
                if (false === $chainablePool instanceof ChildDefinition) {
                    $chainablePool = new ChildDefinition($chainablePool);
                }
                $this->processChainedPool($chainablePool, $poolDefinition, $provider, $container, $serviceId, $tags);

                $chainablePools[] = $chainablePool;
            }

            $pool->replaceArgument(0, $chainablePools);
            unset($tags[0]['provider'], $tags[0]['namespace']);
            $i = 1;
        } else {
            $i = 0;
        }

        foreach (self::POOL_ATTRIBUTES as $attr) {
            if (!isset($tags[0][$attr])) {
                // no-op
            } elseif ('reset' === $attr) {
                if ($tags[0][$attr]) {
                    $pool->addTag('kernel.reset', ['method' => $tags[0][$attr]]);
                }
            } elseif ('early_expiration_message_bus' === $attr) {
                $this->messageHandlerIsNeeded = true;
                $pool->addMethodCall('setCallbackWrapper', [(new Definition(EarlyExpirationDispatcher::class))
                    ->addArgument(new Reference($tags[0]['early_expiration_message_bus']))
                    ->addArgument(new Reference('reverse_container'))
                    ->addArgument((new Definition('callable'))
                        ->setFactory([new Reference($serviceId), 'setCallbackWrapper'])
                        ->addArgument(null)
                    ),
                ]);
                $pool->addTag('container.reversible');
            } elseif ('namespace' !== $attr || ArrayAdapter::class !== $class) {
                $argument = $tags[0][$attr];

                if ('default_lifetime' === $attr && !is_numeric($argument)) {
                    $argument = (new Definition('int', [$argument]))
                        ->setFactory([ParameterNormalizer::class, 'normalizeDuration']);
                }

                $pool->replaceArgument($i++, $argument);
            }
            unset($tags[0][$attr]);
        }
        if (!empty($tags[0])) {
            throw new InvalidArgumentException(sprintf('Invalid "cache.pool" tag for service "%s": accepted attributes are "clearer", "provider", "name", "namespace", "default_lifetime", "early_expiration_message_bus" and "reset", found "%s".', $serviceId, implode('", "', array_keys($tags[0]))));
        }

        if (null !== $clearer) {
            $this->clearers[$clearer][$name] = new Reference($serviceId, $container::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        $this->allPools[$name] = new Reference($serviceId, $container::IGNORE_ON_UNINITIALIZED_REFERENCE);
    }

    private function processChainedPool(ChildDefinition $chainablePool, Definition $chainedPool, int|string $provider, ContainerBuilder $container, string $serviceId, array $tags): void
    {
        $chainedTags = [\is_int($provider) ? [] : ['provider' => $provider]];
        while ($chainedPool instanceof ChildDefinition) {
            $chainedPool = $container->findDefinition($chainedPool->getParent());
            if ($poolTag = $chainedPool->getTag('cache.pool')) {
                $chainedTags[0] += $poolTag[0];
            }
        }

        $baseChainablePool = $chainablePool;
        while ($baseChainablePool instanceof ChildDefinition) {
            $baseChainablePool = $container->findDefinition($baseChainablePool->getParent());
            if (ChainAdapter::class === $baseChainablePool->getClass()) {
                throw new InvalidArgumentException(sprintf('Invalid service "%s": chain of adapters cannot reference another chain, found "%s".', $serviceId, $chainablePool->getParent()));
            }
        }

        $i = 0;

        if (isset($chainedTags[0]['provider'])) {
            $chainablePool->replaceArgument($i++, new Reference(static::getServiceProvider($container, $chainedTags[0]['provider'])));
        }

        if (isset($tags[0]['namespace']) && ArrayAdapter::class !== $chainedPool->getClass()) {
            $chainablePool->replaceArgument($i++, $tags[0]['namespace']);
        }

        if (isset($tags[0]['default_lifetime'])) {
            $chainablePool->replaceArgument($i++, $tags[0]['default_lifetime']);
        }
    }
}
