<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

/**
 * The three System One primitives, using TypeSafe's exact vocabulary.
 *
 * ```php
 * QuestionType::from('noul')->hasConfidence(); // false
 * QuestionType::Score->hasConfidence();        // true
 * ```
 */
enum QuestionType: string
{
    case Choice = 'choice';
    case Score = 'score';
    case Noul = 'noul';

    /**
     * Whether answers of this type carry a server-side `confidence` field.
     * Noul answers do not; their certainty is derived client-side from `noul`.
     */
    public function hasConfidence(): bool
    {
        return $this !== self::Noul;
    }
}
