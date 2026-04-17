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
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\BlockNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Scans a template for a file-scoped trans_default_domain declaration, validates it,
 * and stores the domain as an attribute on the ModuleNode for use by
 * TranslationDefaultDomainNodeVisitor (priority -10).
 *
 * @author Matthias Pigulla <mp@webfactory.de>
 */
final class TranslationFileScopeScannerNodeVisitor implements NodeVisitorInterface
{
    private ?ModuleNode $currentModule = null;
    private int $blockDepth = 0;

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ModuleNode) {
            $this->currentModule = $node;
            $this->blockDepth = 0;
        }

        if ($node instanceof BlockNode) {
            ++$this->blockDepth;
        }

        if ($node instanceof TransDefaultDomainNode && $node->getAttribute('file_scope')) {
            if ($this->blockDepth > 0) {
                throw new SyntaxError('trans_default_domain with "file_scope" must be declared at the top level, not inside a block.', $node->getTemplateLine());
            }
            if ($this->currentModule->hasAttribute('file_scope_domain')) {
                throw new SyntaxError('trans_default_domain with "file_scope" may only be declared once per template.', $node->getTemplateLine());
            }
            $this->currentModule->setAttribute('file_scope_domain', $node->getNode('expr')->getAttribute('value'));
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($node instanceof BlockNode) {
            --$this->blockDepth;

            return $node;
        }

        if ($node instanceof TransDefaultDomainNode && $node->getAttribute('file_scope')) {
            return null;
        }

        if ($node instanceof ModuleNode) {
            foreach ($node->getAttribute('embedded_templates') as $embeddedModule) {
                if ($embeddedModule->hasAttribute('file_scope_domain')) {
                    throw new SyntaxError('trans_default_domain with "file_scope" may not be used inside an embed block.', $embeddedModule->getTemplateLine());
                }
            }
        }

        return $node;
    }

    public function getPriority(): int
    {
        return -20;
    }
}
