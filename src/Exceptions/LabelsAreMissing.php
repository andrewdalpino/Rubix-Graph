<?php

namespace Rubix\ML\Exceptions;

class LabelsAreMissing extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A Labeled dataset object is required.');
    }
}
