<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Contracts;

use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Questions\QuestionType;

/**
 * One answer inside an Outcome. Concrete classes: ChoiceAnswer, ScoreAnswer, NoulAnswer and
 * GenericAnswer (unknown types, preserved untouched).
 *
 * ```php
 * $answer = $outcome->answer('department');
 * $answer->certainty();          // 0.82 — server confidence for choice/score, |2p−1| for noul
 * $answer->band();               // Certainty::Medium
 * $answer->get('probabilities'); // raw field access, also for fields this package does not model
 * ```
 */
interface Answer extends Arrayable
{
    public function id(): string;

    /**
     * The primitive this answer belongs to, or null for unknown answer types.
     */
    public function type(): ?QuestionType;

    /**
     * A single 0–1 number expressing how sure the model is. For choice/score this is the server's
     * `confidence`; for noul it is derived client-side as `|2·noul − 1|`.
     */
    public function certainty(): float;

    public function band(?Thresholds $thresholds = null): Certainty;

    /**
     * The untouched answer array as decoded from the response.
     *
     * @return array<string, mixed>
     */
    public function raw(): array;

    public function get(string $key, mixed $default = null): mixed;

    public function withThresholds(Thresholds $thresholds): static;
}
