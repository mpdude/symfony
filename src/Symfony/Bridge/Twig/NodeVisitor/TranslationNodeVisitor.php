<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Twig\NodeVisitor;

use Symfony\Bridge\Twig\Node\TransNode;
use Twig\Environment;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * TranslationNodeVisitor extracts translation messages.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class TranslationNodeVisitor implements NodeVisitorInterface
{
    public const UNDEFINED_DOMAIN = '_undefined';

    private bool $enabled = false;
    private array $messages = [];
    private array $nodeMessageIndex = [];

    public function enable(): void
    {
        $this->enabled = true;
        $this->messages = [];
        $this->nodeMessageIndex = [];
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->messages = [];
        $this->nodeMessageIndex = [];
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($this->enabled) {
            $this->extractFromNode($node);
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($this->enabled && $node instanceof ModuleNode) {
            $this->extractFromEmbeddedTemplates($node->getAttribute('embedded_templates'));
        }

        return $node;
    }

    private function extractFromEmbeddedTemplates(Node $embeddedTemplates): void
    {
        foreach ($embeddedTemplates as $embeddedModule) {
            $this->extractFromTree($embeddedModule);

            $nested = $embeddedModule->getAttribute('embedded_templates');
            if ($nested instanceof Node) {
                $this->extractFromEmbeddedTemplates($nested);
            }
        }
    }

    private function extractFromTree(Node $node): void
    {
        $this->extractFromNode($node);

        foreach ($node as $child) {
            $this->extractFromTree($child);
        }
    }

    private function extractFromNode(Node $node): void
    {
        if (
            $node instanceof FilterExpression
            && 'trans' === ($node->hasAttribute('twig_callable') ? $node->getAttribute('twig_callable')->getName() : $node->getNode('filter')->getAttribute('value'))
            && $node->getNode('node') instanceof ConstantExpression
        ) {
            // extract constant nodes with a trans filter
            $message = [
                $node->getNode('node')->getAttribute('value'),
                $this->getReadDomainFromArguments($node->getNode('arguments'), 1),
            ];
        } elseif (
            $node instanceof FunctionExpression
            && 't' === $node->getAttribute('name')
        ) {
            $nodeArguments = $node->getNode('arguments');

            if (!$nodeArguments->getIterator()->current() instanceof ConstantExpression) {
                return;
            }

            $message = [
                $this->getReadMessageFromArguments($nodeArguments, 0),
                $this->getReadDomainFromArguments($nodeArguments, 2),
            ];
        } elseif ($node instanceof TransNode) {
            // extract trans nodes
            $message = [
                $node->getNode('body')->getAttribute('data'),
                $node->hasNode('domain') ? $this->getReadDomainFromNode($node->getNode('domain')) : null,
            ];
        } elseif (
            $node instanceof FilterExpression
            && 'trans' === ($node->hasAttribute('twig_callable') ? $node->getAttribute('twig_callable')->getName() : $node->getNode('filter')->getAttribute('value'))
            && $node->getNode('node') instanceof ConcatBinary
            && $value = $this->getConcatValueFromNode($node->getNode('node'), null)
        ) {
            $message = [
                $value,
                $this->getReadDomainFromArguments($node->getNode('arguments'), 1),
            ];
        } else {
            return;
        }

        // A node may be visited twice: once during an inner traversal (embedded
        // template, domain not yet injected) and again during the outer leaveNode
        // post-processing (domain already injected). The second visit wins.
        $nodeId = spl_object_id($node);
        if (isset($this->nodeMessageIndex[$nodeId])) {
            $this->messages[$this->nodeMessageIndex[$nodeId]] = $message;
        } else {
            $this->nodeMessageIndex[$nodeId] = count($this->messages);
            $this->messages[] = $message;
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    private function getReadMessageFromArguments(Node $arguments, int $index): ?string
    {
        if ($arguments->hasNode('message')) {
            $argument = $arguments->getNode('message');
        } elseif ($arguments->hasNode($index)) {
            $argument = $arguments->getNode($index);
        } else {
            return null;
        }

        return $this->getReadMessageFromNode($argument);
    }

    private function getReadMessageFromNode(Node $node): ?string
    {
        if ($node instanceof ConstantExpression) {
            return $node->getAttribute('value');
        }

        return null;
    }

    private function getReadDomainFromArguments(Node $arguments, int $index): ?string
    {
        if ($arguments->hasNode('domain')) {
            $argument = $arguments->getNode('domain');
        } elseif ($arguments->hasNode($index)) {
            $argument = $arguments->getNode($index);
        } else {
            return null;
        }

        return $this->getReadDomainFromNode($argument);
    }

    private function getReadDomainFromNode(Node $node): ?string
    {
        if ($node instanceof ConstantExpression) {
            return $node->getAttribute('value');
        }

        if (
            $node instanceof FunctionExpression
            && 'constant' === $node->getAttribute('name')
        ) {
            $nodeArguments = $node->getNode('arguments');
            if ($nodeArguments->getIterator()->current() instanceof ConstantExpression) {
                $constantName = $nodeArguments->getIterator()->current()->getAttribute('value');
                if (\defined($constantName)) {
                    $value = \constant($constantName);
                    if (\is_string($value)) {
                        return $value;
                    }
                }
            }
        }

        return self::UNDEFINED_DOMAIN;
    }

    private function getConcatValueFromNode(Node $node, ?string $value): ?string
    {
        if ($node instanceof ConcatBinary) {
            foreach ($node as $nextNode) {
                if ($nextNode instanceof ConcatBinary) {
                    $nextValue = $this->getConcatValueFromNode($nextNode, $value);
                    if (null === $nextValue) {
                        return null;
                    }
                    $value .= $nextValue;
                } elseif ($nextNode instanceof ConstantExpression) {
                    $value .= $nextNode->getAttribute('value');
                } else {
                    // this is a node we cannot process (variable, or translation in translation)
                    return null;
                }
            }
        } elseif ($node instanceof ConstantExpression) {
            $value .= $node->getAttribute('value');
        }

        return $value;
    }
}
