<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\State;

use AgUi\Bridge\SymfonyAi\Exception\InvalidStateException;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Binds a route's state class — an application object tools inject like any
 * service — to the shared state of the protocol.
 *
 * Before a run it fills that very instance from RunAgentInput.state, during the run it
 * gives AgUi snapshots to diff into STATE_DELTA, and after the run it puts
 * the instance back the way it was, so the next run on the same worker starts clean.
 */
final class SharedState
{
    private NormalizerInterface&DenormalizerInterface $serializer;

    /** The instance as it was before any run, normalized; null until the first run. */
    private mixed $defaults = null;
    private bool $defaultsCaptured = false;

    public function __construct(
        private readonly object $state,
        (NormalizerInterface&DenormalizerInterface)|null $serializer = null,
        private readonly ?ValidatorInterface $validator = null,
    ) {
        $this->serializer = $serializer ?? self::createSerializer();
    }

    /** The state class instance tools inject. */
    public function object(): object
    {
        return $this->state;
    }

    /**
     * Checks that a client's state fits the state class, without touching the instance.
     *
     * @throws InvalidStateException
     */
    public function assertValid(mixed $data): void
    {
        if (self::isEmpty($data)) {
            return;
        }

        try {
            $candidate = $this->serializer->denormalize($data, $this->state::class);
        } catch (SerializerException|\TypeError $e) {
            throw new InvalidStateException(\sprintf('The shared state does not fit "%s": %s', $this->state::class, $e->getMessage()), previous: $e);
        }

        if (null === $this->validator) {
            return;
        }

        $violations = $this->validator->validate($candidate);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = ('' === $violation->getPropertyPath() ? '' : $violation->getPropertyPath().': ').$violation->getMessage();
            }

            throw new InvalidStateException(\sprintf('The shared state breaks the constraints of "%s": %s', $this->state::class, implode(' ', $messages)));
        }
    }

    /** Fills the instance with the client's state; an absent state leaves the defaults. */
    public function load(mixed $data): void
    {
        if (!$this->defaultsCaptured) {
            $this->defaults = $this->snapshot();
            $this->defaultsCaptured = true;
        }

        if (self::isEmpty($data)) {
            return;
        }

        $this->populate($data);
    }

    /** The state as JSON-ready data: what STATE_SNAPSHOT carries, and what deltas diff. */
    public function snapshot(): mixed
    {
        return $this->serializer->normalize($this->state);
    }

    /** What the model reads about the state. */
    public function describe(): string
    {
        if ($this->state instanceof DescribesItself) {
            return $this->state->describeForAgent();
        }

        return "The shared state of this conversation, which the user sees and can change too:\n"
            .json_encode($this->snapshot(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    public function reset(): void
    {
        if ($this->state instanceof ResetInterface) {
            $this->state->reset();

            return;
        }

        if ($this->defaultsCaptured && \is_array($this->defaults)) {
            $this->populate($this->defaults);
        }
    }

    private function populate(mixed $data): void
    {
        try {
            $this->serializer->denormalize($data, $this->state::class, context: [AbstractNormalizer::OBJECT_TO_POPULATE => $this->state]);
        } catch (SerializerException|\TypeError $e) {
            throw new InvalidStateException(\sprintf('The shared state does not fit "%s": %s', $this->state::class, $e->getMessage()), previous: $e);
        }
    }

    private static function isEmpty(mixed $data): bool
    {
        return null === $data || [] === $data;
    }

    private static function createSerializer(): Serializer
    {
        $propertyInfo = new PropertyInfoExtractor(
            typeExtractors: [new PhpStanExtractor(), new ReflectionExtractor()],
        );

        return new Serializer([
            new BackedEnumNormalizer(),
            new DateTimeNormalizer(),
            new ArrayDenormalizer(),
            new ObjectNormalizer(propertyTypeExtractor: $propertyInfo),
        ]);
    }
}
