<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Twig\TokenParser;

use Symfony\Bridge\Twig\Node\TransDefaultDomainNode;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Token Parser for the 'trans_default_domain' tag.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class TransDefaultDomainTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();
        $expr = $this->parser->parseExpression();

        $fileScope = false;
        if ($stream->nextIf(Token::NAME_TYPE, 'file_scope')) {
            if (!$expr instanceof ConstantExpression) {
                throw new SyntaxError('The "file_scope" modifier requires the domain to be a constant expression.', $token->getLine(), $stream->getSourceContext());
            }
            $fileScope = true;
        }

        $stream->expect(Token::BLOCK_END_TYPE);

        return new TransDefaultDomainNode($expr, $token->getLine(), $fileScope);
    }

    public function getTag(): string
    {
        return 'trans_default_domain';
    }
}
