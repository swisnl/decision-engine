<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Typed;

use Swis\DecisionEngine\Answers\ChoiceAnswer;
use Swis\DecisionEngine\Answers\NoulAnswer;
use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Attributes;
use Swis\DecisionEngine\Contracts\Answer;
use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Exceptions\OutcomeMismatchException;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Support\Enums;

/**
 * The questions a TypedOutcome class declares, read once per class from its property attributes,
 * and the strict mapping from an Outcome back onto those properties.
 *
 * Property types: choice → `ChoiceAnswer` | `string` | backed enum; score → `ScoreAnswer` | `float`;
 * noul → `NoulAnswer` | `float`. Anything else is a definition error (InvalidDecisionException).
 *
 * @internal use TypedOutcome::questions() / fromOutcome()
 */
final class Schema
{
    /**
     * @var array<class-string, self>
     */
    private static array $cache = [];

    /**
     * @param  class-string  $class
     * @param  array<string, array{property: \ReflectionProperty, question: Question, answer: class-string<Answer>, kind: string, enum: class-string<\BackedEnum>|null}>  $fields  keyed by question id
     */
    private function __construct(
        private readonly string $class,
        private readonly array $fields,
    ) {}

    /**
     * @param  class-string  $class
     */
    public static function for(string $class): self
    {
        return self::$cache[$class] ??= self::read($class);
    }

    /**
     * @return list<Question>
     */
    public function questions(): array
    {
        return array_values(array_map(static fn(array $field): Question => $field['question'], $this->fields));
    }

    /**
     * Assign every declared property on `$target` from `$outcome`; throws on the first mismatch.
     */
    public function hydrate(object $target, Outcome $outcome): void
    {
        foreach ($this->fields as $id => $field) {
            $name = $this->class . '::$' . $field['property']->getName();

            if (! $outcome->has($id)) {
                throw OutcomeMismatchException::missing($name, $id);
            }

            $answer = $outcome->answer($id);

            if (! $answer instanceof $field['answer']) {
                throw OutcomeMismatchException::type($name, $id, self::short($field['answer']), self::short($answer::class));
            }

            $field['property']->setValue($target, self::value($name, $id, $answer, $field['kind'], $field['enum']));
        }
    }

    /**
     * @param  class-string<\BackedEnum>|null  $enum
     */
    private static function value(string $name, string $id, Answer $answer, string $kind, ?string $enum): mixed
    {
        return match (true) {
            $kind === 'answer' => $answer,
            $answer instanceof ChoiceAnswer && $kind === 'string' => $answer->choice,
            $answer instanceof ChoiceAnswer && $enum !== null => Enums::tryFrom($enum, $answer->choice) ?? throw OutcomeMismatchException::enumCase($name, $id, $enum, $answer->choice),
            $answer instanceof ScoreAnswer => $answer->score,
            $answer instanceof NoulAnswer => $answer->noul,
            default => throw OutcomeMismatchException::type($name, $id, $kind, self::short($answer::class)),
        };
    }

    /**
     * @param  class-string  $class
     */
    private static function read(string $class): self
    {
        $fields = [];

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            $attributes = [
                ...$property->getAttributes(Attributes\Choice::class),
                ...$property->getAttributes(Attributes\Score::class),
                ...$property->getAttributes(Attributes\Noul::class),
            ];

            if ($attributes === []) {
                continue;
            }

            $name = $class . '::$' . $property->getName();
            $attribute = $attributes[0]->newInstance();
            $id = $attribute->id ?? $property->getName();

            if (count($attributes) > 1 || $property->isStatic()) {
                throw InvalidDecisionException::single($id, "{$name} must be a non-static property with exactly one of #[Choice], #[Score] or #[Noul].");
            }

            if (isset($fields[$id])) {
                throw InvalidDecisionException::single($id, "{$name} reuses question id [{$id}].");
            }

            $type = $property->getType();

            if (! $type instanceof \ReflectionNamedType || $type->allowsNull()) {
                throw InvalidDecisionException::single($id, "{$name} needs a single, non-nullable type.");
            }

            $fields[$id] = ['property' => $property] + match (true) {
                $attribute instanceof Attributes\Choice => self::choice($name, $id, $attribute, $type->getName()),
                $attribute instanceof Attributes\Score => self::scalar($name, $id, Score::make($id, $attribute->instructions, $attribute->levels), ScoreAnswer::class, $type->getName()),
                default => self::scalar($name, $id, Noul::make($id, $attribute->instructions)->criteria($attribute->true, $attribute->false), NoulAnswer::class, $type->getName()),
            };
        }

        if ($fields === []) {
            throw InvalidDecisionException::single($class, "{$class} declares no #[Choice], #[Score] or #[Noul] properties.");
        }

        return new self($class, $fields);
    }

    /**
     * @return array{question: Question, answer: class-string<Answer>, kind: string, enum: class-string<\BackedEnum>|null}
     */
    private static function choice(string $name, string $id, Attributes\Choice $attribute, string $type): array
    {
        $enum = Enums::isBacked($type) ? $type : null;
        $options = $attribute->options;

        if ($enum === null && $type !== ChoiceAnswer::class && $type !== 'string') {
            throw InvalidDecisionException::single($id, "{$name} is a choice; type it as ChoiceAnswer, string or a backed enum, not {$type}.");
        }

        if ($enum !== null && $options !== null && $options !== $enum) {
            throw InvalidDecisionException::single($id, "{$name} takes its options from {$enum}; leave `options` out or pass {$enum}::class.");
        }

        $options ??= $enum ?? throw InvalidDecisionException::single($id, "{$name} needs `options`: an array of option → description or a backed enum class.");

        if (is_string($options)) {
            $options = Enums::isBacked($options) ? Enums::options($options) : throw InvalidDecisionException::single($id, "{$name} `options` must be an array or a backed enum class, got [{$options}].");
        }

        return [
            'question' => Choice::make($id, $attribute->instructions, $options),
            'answer' => ChoiceAnswer::class,
            'kind' => $enum === null ? ($type === 'string' ? 'string' : 'answer') : 'enum',
            'enum' => $enum,
        ];
    }

    /**
     * @param  class-string<Answer>  $answer
     * @return array{question: Question, answer: class-string<Answer>, kind: string, enum: null}
     */
    private static function scalar(string $name, string $id, Question $question, string $answer, string $type): array
    {
        if ($type !== $answer && $type !== 'float') {
            throw InvalidDecisionException::single($id, "{$name} is a {$question->type()->value}; type it as " . self::short($answer) . " or float, not {$type}.");
        }

        return ['question' => $question, 'answer' => $answer, 'kind' => $type === 'float' ? 'float' : 'answer', 'enum' => null];
    }

    private static function short(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
