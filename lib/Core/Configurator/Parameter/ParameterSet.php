<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

use Webmozart\Assert\Assert;

/**
 * The ordered parameters of a product, as they apply to one configuration.
 *
 * A parameter that does not apply to the configuration (privacy strips on a
 * slatted fence, the position of a gate that is not there) can be hidden: it
 * keeps its place and resolves as usual, so the names stay the same for the
 * resolver, but the form, the summary and the option prices leave it out.
 *
 * @implements \IteratorAggregate<string, Parameter>
 */
final class ParameterSet implements \IteratorAggregate
{
    /** @var array<string, Parameter> */
    private readonly array $parameters;

    /** @var array<string, true> */
    private array $hidden = [];

    public function __construct(Parameter ...$parameters)
    {
        $indexed = [];
        foreach ($parameters as $parameter) {
            Assert::keyNotExists($indexed, $parameter->name(), 'Duplicate parameter "%s".');
            $indexed[$parameter->name()] = $parameter;
        }
        $this->parameters = $indexed;
    }

    public function get(string $name): Parameter
    {
        Assert::keyExists($this->parameters, $name, 'Unknown parameter "%s".');

        return $this->parameters[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    /**
     * A copy with these parameters left out of the form, the summary and the option prices.
     */
    public function hide(string ...$names): self
    {
        $copy = clone $this;
        foreach ($names as $name) {
            Assert::keyExists($this->parameters, $name, 'Unknown parameter "%s".');
            $copy->hidden[$name] = true;
        }

        return $copy;
    }

    public function isHidden(string $name): bool
    {
        return isset($this->hidden[$name]);
    }

    /**
     * Every parameter, hidden ones included (the resolver goes through all of them).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->parameters);
    }

    /**
     * @return array<string, list<Parameter>> parameters by group, groups in ParameterGroup::ORDER
     */
    public function grouped(): array
    {
        $groups = array_fill_keys(ParameterGroup::ORDER, []);
        foreach ($this->visible() as $parameter) {
            $groups[$parameter->group()][] = $parameter;
        }

        return array_filter($groups);
    }

    /**
     * The parameters the customer sees.
     *
     * @return \ArrayIterator<string, Parameter>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->visible());
    }

    /**
     * @return array<string, Parameter>
     */
    private function visible(): array
    {
        return array_diff_key($this->parameters, $this->hidden);
    }
}
