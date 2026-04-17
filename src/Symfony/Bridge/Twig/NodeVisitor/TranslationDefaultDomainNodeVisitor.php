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

use Symfony\Bridge\Twig\Node\TransDefaultDomainNode;
use Symfony\Bridge\Twig\Node\TransNode;
use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\EmptyNode;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\Variable\AssignContextVariable;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\SetNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class TranslationDefaultDomainNodeVisitor implements NodeVisitorInterface
{
    private Scope $scope;

    public function __construct()
    {
        $this->scope = new Scope();
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ModuleNode) {
            $this->scope = $this->scope->enter();
        }

        // Inherit the file-scoped domain when entering new blocks
        if ($node instanceof BlockNode) {
            $inheritedFileScopeDomain = $this->scope->has('file_scope_domain') ? $this->scope->get('file_scope_domain') : null;
            $this->scope = $this->scope->enter();
            if (null !== $inheritedFileScopeDomain) {
                $this->scope->set('file_scope_domain', $inheritedFileScopeDomain);
            }
        }

        if ($node instanceof TransDefaultDomainNode) {
            if ($node->getAttribute('file_scope')) {
                // A static string value is guaranteed by the token parser.
                $this->scope->set('file_scope_domain', $node->getNode('expr')->getAttribute('value'));
            }

            if ($node->getNode('expr') instanceof ConstantExpression) {
                $this->scope->set('domain', $node->getNode('expr'));

                return $node;
            }

            if (null === $templateName = $node->getTemplateName()) {
                throw new \LogicException('Cannot traverse a node without a template name.');
            }

            $var = '__internal_trans_default_domain'.hash('xxh128', $templateName);

            $name = new AssignContextVariable($var, $node->getTemplateLine());
            $this->scope->set('domain', new ContextVariable($var, $node->getTemplateLine()));

            return new SetNode(false, new Nodes([$name]), new Nodes([$node->getNode('expr')]), $node->getTemplateLine());
        }

        if (!$this->scope->has('domain')) {
            return $node;
        }

        $this->injectDomainExprIntoTransNode($node, $this->scope->get('domain'));

        return $node;
    }

    /**
     * Injects $domainExpr as the translation domain into $node if $node is a trans
     * call without an already-set domain.
     */
    private function injectDomainExprIntoTransNode(Node $node, Node $domainExpr): void
        {
            if ($node instanceof FilterExpression && 'trans' === ($node->hasAttribute('twig_callable') ? $node->getAttribute('twig_callable')->getName() : $node->getNode('filter')->getAttribute('value'))) {
                $arguments = $node->getNode('arguments');

                if ($arguments instanceof EmptyNode) {
                    $arguments = new Nodes();
                    $node->setNode('arguments', $arguments);
                }

                if ($this->isNamedArguments($arguments)) {
                    if (!$arguments->hasNode('domain') && !$arguments->hasNode(1)) {
                        $arguments->setNode('domain', $domainExpr);
                    }
                } elseif (!$arguments->hasNode(1)) {
                    if (!$arguments->hasNode(0)) {
                        $arguments->setNode(0, new ArrayExpression([], $node->getTemplateLine()));
                    }
                    $arguments->setNode(1, $domainExpr);
                }
            } elseif ($node instanceof TransNode) {
                if (!$node->hasNode('domain')) {
                    $node->setNode('domain', $domainExpr);
                }
            }
        }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($node instanceof TransDefaultDomainNode) {
            return null;
        }

        // If a file-scoped domain was declared, post-process all embedded templates
        // (which were already traversed during their own inner parse, before this
        // module's NodeVisitor pass ran).
        if ($node instanceof ModuleNode && $this->scope->has('file_scope_domain')) {
            $this->injectFileScopeDomain(
                $node->getAttribute('embedded_templates'),
                $this->scope->get('file_scope_domain'),
            );
        }

        if ($node instanceof BlockNode || $node instanceof ModuleNode) {
            $this->scope = $this->scope->leave();
        }

        return $node;
    }

    private function injectFileScopeDomain(Node $embeddedTemplates, string $domain): void
    {
        foreach ($embeddedTemplates as $embeddedModule) {
            $this->injectDomainIntoNode($embeddedModule, $domain);

            // Recurse into nested embedded templates (embeds within embeds).
            $nested = $embeddedModule->getAttribute('embedded_templates');
            if ($nested instanceof Node) {
                $this->injectFileScopeDomain($nested, $domain);
            }
        }
    }

    private function injectDomainIntoNode(Node $node, string $domain): void
    {
        $this->injectDomainExprIntoTransNode($node, new ConstantExpression($domain, $node->getTemplateLine()));

        foreach ($node as $child) {
            $this->injectDomainIntoNode($child, $domain);
        }
    }

    public function getPriority(): int
    {
        return -10;
    }

    private function isNamedArguments(Node $arguments): bool
    {
        foreach ($arguments as $name => $node) {
            if (!\is_int($name)) {
                return true;
            }
        }

        return false;
    }
}
