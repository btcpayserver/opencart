<?php

declare(strict_types=1);

namespace BTCPayServer\Result;

abstract class AbstractResult implements \ArrayAccess
{
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        $data = $this->getData();
        return array_key_exists($offset, $data);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $data = $this->getData();
        return $data[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        throw new \RuntimeException('You should not change the data in a result.');
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new \RuntimeException('You should not change the data in a result.');
    }
}
